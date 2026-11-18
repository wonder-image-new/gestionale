<?php

namespace Wonder\Plugin\Gestionale\Resources\SalesAgents;

use Wonder\App\ResourceSchema\ApiSchema;
use Wonder\App\ResourceSchema\FormField;
use Wonder\App\ResourceSchema\NavigationSchema;
use Wonder\App\ResourceSchema\PageSchema;
use Wonder\App\ResourceSchema\PermissionSchema;
use Wonder\App\ResourceSchema\RepeaterColumn;
use Wonder\App\ResourceSchema\RepeaterRelation;
use Wonder\App\ResourceSchema\TableColumn;
use Wonder\Elements\Components\Card;
use Wonder\Elements\Components\Container;
use Wonder\Elements\Components\SectionTitle;
use Wonder\Elements\Form\Form;
use Wonder\App\Support\Repeater;
use Wonder\Plugin\Gestionale\Gestionale;
use Wonder\Plugin\Gestionale\Models\Contacts\Company;
use Wonder\Plugin\Gestionale\Models\Contacts\Contact;
use Wonder\Plugin\Gestionale\Models\Catalog\Category;
use Wonder\Plugin\Gestionale\Models\SalesAgents\SalesAgent;
use Wonder\Plugin\Gestionale\Models\SalesAgents\SalesAgentCommissionRule;
use Wonder\Plugin\Gestionale\Models\SalesAgents\SalesAgentTarget;
use Wonder\Plugin\Gestionale\Resources\GestionaleResource;
use Wonder\Plugin\Gestionale\Resources\Pricing\PriceListResource;
use Wonder\Plugin\Gestionale\Support\Catalog\CategoryTree;
use Wonder\Plugin\Gestionale\Support\Errors\UserError;
use Wonder\Plugin\Gestionale\Support\Numbers;
use Wonder\Plugin\Gestionale\Support\Sales\OfficeOrderEditor;
use Wonder\Plugin\Gestionale\Support\SalesAgents\SalesAgentNetwork;
use Wonder\Plugin\Gestionale\Support\SalesAgents\SalesAgentPriceLists;
use Wonder\Plugin\Gestionale\Support\SalesAgents\SalesAgentUsers;
use Wonder\Plugin\Gestionale\Support\SalesAgents\SalesAgents;
use Wonder\Plugin\Gestionale\Support\SalesAgents\Targets;

/**
 * «Commerciali» (#77, funzionalità `sales_agents`): chi vende per il negozio e
 * vede solo i propri clienti. Il commerciale è un utente del backend col ruolo
 * `sales_agent`, creato da questa pagina con nome, cognome ed email (riceve
 * la mail per scegliere la password); una scheda per utente.
 *
 * Solo l'ufficio (area `commerciali`) li gestisce. I clienti si assegnano
 * dalla scheda del cliente o dell'azienda.
 */
final class SalesAgentResource extends GestionaleResource
{
    public static string $model = SalesAgent::class;
    public static string $feature = 'sales_agents';
    public static string $docsPage = 'anagrafiche/commerciali';

    public static function path(): string
    {
        return 'app/gestionale/commerciali';
    }

    public static function icon(): string
    {
        return 'bi-person-badge';
    }

    public static function titleLabel(): string
    {
        return 'Commerciali';
    }

    public static function textSchema(): array
    {
        return [
            'label' => 'commerciale',
            'plural_label' => 'commerciali',
            'last' => 'ultimi',
            'all' => 'tutti',
            'article' => 'i',
            'full' => 'attivo',
            'empty' => 'non attivo',
            'this' => 'questo',
        ];
    }

    public static function labelSchema(): array
    {
        return [
            'user_id' => 'Commerciale',
            'contact_id' => 'Scheda anagrafica',
            'commission_percent' => 'Provvigione %',
            'max_discount_percent' => 'Sconto massimo %',
            'active' => 'Stato',
            'note' => 'Note',
            'customers' => 'Clienti',
            'price_lists' => 'Listini',
            'default_price_list_id' => 'Listino predefinito',
            'parent_id' => 'Sopra di lui',
            'network_edit' => 'Può modificare la sua rete',
        ];
    }

    /** I campi dell'utente nuovo: non sono colonne del commerciale. */
    private const USER_FIELDS = ['user_name', 'user_surname', 'user_email'];

    /** Elenco e predefinito chiesti dal form, scritti dopo il salvataggio; null = non toccare. */
    private static ?array $priceLists = null;

    public static function formSchema(): array
    {
        return [
            // In modifica l'utente si vede ma non si cambia.
            FormField::key('user_id')
                ->select(['' => 'Nessuno'] + SalesAgents::userOptions())
                ->label('Utente')
                ->disabled(),
            FormField::key('user_name')->text()->label('Nome')->required(),
            FormField::key('user_surname')->text()->label('Cognome'),
            FormField::key('user_email')->email()->label('Email')->required(),
            FormField::key('contact_id')->searchText(OfficeOrderEditor::customersUrl())
                ->emptyText('Nessun contatto trovato: crealo dai Contatti.')
                ->label('Scheda anagrafica'),
            FormField::key('commission_percent')->number()->decimal(2)->label('Provvigione %'),
            FormField::key('max_discount_percent')->number()->decimal(2)->label('Sconto massimo %'),
            FormField::key('active')
                ->select(['true' => 'Attivo', 'false' => 'Non attivo'])
                ->value('true')
                ->label('Stato'),
            FormField::key('note')->textarea()->label('Note'),
            FormField::key('parent_id')
                ->select(['' => 'Nessuno'] + static::parentOptions())
                ->label('Sopra di lui'),
            FormField::key('network_edit')
                ->select(['default' => 'Come da impostazioni', 'true' => 'Sì', 'false' => 'No'])
                ->value('default')
                ->label('Può modificare la sua rete'),
            FormField::key('price_lists')->checkbox()->options(static::priceListOptions())->value([])->label(''),
            FormField::key('default_price_list_id')
                ->select(['' => 'Nessuno'] + static::priceListOptions())
                ->label('Listino predefinito'),
            // Solo con la funzionalità: un form senza il campo svuoterebbe le regole.
            ...(Gestionale::feature('sales_agent_commissions') ? [static::rulesField()] : []),
            ...(Gestionale::feature('sales_agent_targets') ? [static::targetsField()] : []),
        ];
    }

    /** Gli obiettivi per mese o per anno. */
    private static function targetsField(): FormField
    {
        $months = ['' => 'Tutto l\'anno'];
        foreach (['Gennaio', 'Febbraio', 'Marzo', 'Aprile', 'Maggio', 'Giugno', 'Luglio', 'Agosto', 'Settembre', 'Ottobre', 'Novembre', 'Dicembre'] as $i => $name) {
            $months[(string) ($i + 1)] = $name;
        }

        return FormField::key('targets')
            ->repeater([
                RepeaterColumn::key('id')->hidden(),
                RepeaterColumn::key('period')->select(SalesAgentTarget::PERIODS)->label('Periodo')->columnSpan(2),
                RepeaterColumn::key('year')->number()->label('Anno')->columnSpan(2),
                RepeaterColumn::key('month')->select($months)->label('Mese')->columnSpan(3),
                RepeaterColumn::key('amount')->number()->decimal(2)->label('Obiettivo')->columnFill(),
            ])
            ->relation(
                RepeaterRelation::make(SalesAgentTarget::$table, 'sales_agent_id')
                    ->model(SalesAgentTarget::class)
                    ->positionKey('position')
                    ->softDelete(false)
            )
            ->nested()
            ->repeaterSortable()
            ->repeaterAddLabel('Aggiungi obiettivo')
            ->repeaterDeleteTitle('Elimina obiettivo')
            ->repeaterDeleteText('Confermi l\'eliminazione di questo obiettivo?')
            ->repeaterDeleteCancelLabel('Annulla')
            ->repeaterDeleteConfirmLabel('Elimina')
            ->repeaterDeleteConfirmClass('btn btn-danger')
            ->label('');
    }

    /** Le regole di provvigione per prodotto o categoria. */
    private static function rulesField(): FormField
    {
        return FormField::key('commission_rules')
            ->repeater([
                RepeaterColumn::key('id')->hidden(),
                RepeaterColumn::key('product_id')->select(['' => 'Nessuno'] + PriceListResource::productOptions())->label('Prodotto')->columnFill(),
                RepeaterColumn::key('category_id')->select(static::categoryOptions())->label('Oppure categoria')->columnSpan(4),
                RepeaterColumn::key('percent')->number()->decimal(2)->label('Provvigione %')->columnSpan(2),
            ])
            ->relation(
                RepeaterRelation::make(SalesAgentCommissionRule::$table, 'sales_agent_id')
                    ->model(SalesAgentCommissionRule::class)
                    ->positionKey('position')
                    ->softDelete(false)
            )
            ->nested()
            ->repeaterSortable()
            ->repeaterAddLabel('Aggiungi regola')
            ->repeaterDeleteTitle('Elimina regola')
            ->repeaterDeleteText('Confermi l\'eliminazione di questa regola?')
            ->repeaterDeleteCancelLabel('Annulla')
            ->repeaterDeleteConfirmLabel('Elimina')
            ->repeaterDeleteConfirmClass('btn btn-danger')
            ->label('');
    }

    /** @return array<string, string> */
    public static function categoryOptions(): array
    {
        $options = CategoryTree::options(static::rowsOf(Category::class, [], 'position'));
        unset($options['']);

        return ['' => 'Nessuna'] + $options;
    }

    public static function formLayoutSchema(): ?Form
    {
        // Provvigione e sconto compaiono con la loro funzionalità; spenta, il valore salvato resta.
        $conditions = [];
        if (Gestionale::feature('sales_agent_commissions')) {
            $conditions[] = static::getInput('commission_percent')->columnSpan(6);
        }
        if (Gestionale::feature('sales_agent_discount_limits')) {
            $conditions[] = static::getInput('max_discount_percent')->columnSpan(6);
        }

        return (new Form)->components([
            (new Container)->components([
                (new Card)->components([
                    SectionTitle::make('Commerciale')
                        ->tooltip('Al salvataggio nasce l\'utente col ruolo «Commerciale»: riceve una mail per scegliere la password ed entrare nel pannello. Vede e gestisce solo i clienti e le aziende assegnati a lui e le vendite che ha fatto. Un commerciale non attivo non entra più nei suoi clienti: i suoi restano assegnati finché non li sposti.')
                        ->columnSpan(12),
                    ...(static::currentId() === null
                        ? [
                            static::getInput('user_name')->columnSpan(4),
                            static::getInput('user_surname')->columnSpan(4),
                            static::getInput('user_email')->columnSpan(4),
                        ]
                        : [static::getInput('user_id')->columnSpan(6)]),
                    static::getInput('active')->columnSpan(static::currentId() === null ? 12 : 6),
                    static::getInput('contact_id')->columnSpan(12),
                ])->columns(12)->columnSpan(12),
                ...(SalesAgentNetwork::enabled()
                    ? [(new Card)->components([
                        SectionTitle::make('Rete')
                            ->tooltip('Il commerciale sopra di lui vede i suoi clienti e le sue vendite. «Può modificare la sua rete»: se può cambiare i dati dei commerciali sotto di lui; «Come da impostazioni» segue la scelta delle Impostazioni.')
                            ->columnSpan(12),
                        static::getInput('parent_id')->columnSpan(6),
                        static::getInput('network_edit')->columnSpan(6),
                    ])->columns(12)->columnSpan(12)]
                    : []),
                ...(SalesAgentPriceLists::enabled()
                    ? [(new Card)->components([
                        SectionTitle::make('Listini')
                            ->tooltip('I listini che il commerciale può dare ai suoi clienti; nessuno: solo prezzi di catalogo. Il predefinito va da solo sui clienti nuovi che crea. I listini già sui clienti restano anche se li togli da qui.')
                            ->columnSpan(12),
                        static::getInput('price_lists')->columnSpan(12),
                        static::getInput('default_price_list_id')->columnSpan(6),
                    ])->columns(12)->columnSpan(12)]
                    : []),
                ...(Gestionale::feature('sales_agent_commissions')
                    ? [(new Card)->components([
                        SectionTitle::make('Regole provvigione')
                            ->tooltip('Una % diversa per un prodotto o per una categoria. Vale prima la regola del prodotto, poi quella della categoria (la prima in elenco se il prodotto ne ha più d\'una), poi la «Provvigione %» del commerciale.')
                            ->columnSpan(12),
                        static::getInput('commission_rules')->columnSpan(12),
                    ])->columns(12)->columnSpan(12)]
                    : []),
                ...(Gestionale::feature('sales_agent_targets')
                    ? [(new Card)->components([
                        SectionTitle::make('Obiettivi')
                            ->tooltip('Il valore da raggiungere in un mese o in tutto l\'anno, misurato come scelto nelle Impostazioni ('.Targets::metricLabel().'). Contano solo le vendite del commerciale, non quelle della sua rete. Per lo stesso periodo vale la prima riga in elenco.')
                            ->columnSpan(12),
                        static::getInput('targets')->columnSpan(12),
                    ])->columns(12)->columnSpan(12)]
                    : []),
                (new Card)->components([
                    SectionTitle::make($conditions === [] ? 'Note' : 'Condizioni')->columnSpan(12),
                    ...$conditions,
                    static::getInput('note')->columnSpan(12),
                ])->columns(12)->columnSpan(12),
            ])->columns(12)->columnSpan(12),
        ]);
    }

    public static function tableSchema(): array
    {
        return [
            TableColumn::key('user_id')
                ->text()
                ->link('edit')
                ->formatter(static fn (array $row): string => static::escape(SalesAgents::label($row))),
            TableColumn::key('customers')
                ->text()
                ->formatter(static fn (array $row): string => (string) static::customers((int) ($row['id'] ?? 0))),
            TableColumn::key('active')
                ->booleanBadge()
                ->badgeOn('Attivo', 'bi-check-circle', 'success')
                ->badgeOff('Non attivo', 'bi-dash-circle', 'secondary')
                ->size('little'),
            TableColumn::key('actions')->button()->actions([
                'edit',
                'active' => ['label' => ['true' => 'Disattiva', 'false' => 'Attiva'], 'request' => 'boolean'],
                'delete',
            ]),
        ];
    }

    public static function pageSchema(): PageSchema
    {
        return parent::pageSchema()
            ->disable(['view'])
            ->titles([
                'list' => 'Commerciali',
                'create' => 'Nuovo commerciale',
                'edit' => 'Modifica commerciale',
            ]);
    }

    public static function permissionSchema(): PermissionSchema
    {
        return PermissionSchema::for(static::class)->access(Gestionale::area('commerciali'));
    }

    public static function apiSchema(): ApiSchema
    {
        return ApiSchema::for(static::class)->enabled(false);
    }

    public static function navigationSchema(): NavigationSchema
    {
        return NavigationSchema::for(static::class)
            ->section('anagrafiche', 'Anagrafiche', 'bi-people', 320)
            ->title('Commerciali')
            ->order(40)
            ->access(Gestionale::area('commerciali'))
            ->enabled(static::featureActive());
    }

    /** Persone e aziende assegnate. */
    public static function customers(int $id): int
    {
        if ($id <= 0) {
            return 0;
        }

        return (int) sqlCount(Contact::$table, 'agent_id = '.$id." AND deleted = 'false'")
            + (int) sqlCount(Company::$table, 'agent_id = '.$id." AND deleted = 'false'");
    }

    public static function mutateRequestValues(
        array $values,
        string $action,
        string $context = 'backend',
        ?array $oldValues = null
    ): array {
        // Nuovo commerciale: l'utente nasce qui (o si riusa se è già commerciale).
        if ($action === 'store') {
            $values['user_id'] = SalesAgentUsers::create(
                (string) ($values['user_name'] ?? ''),
                (string) ($values['user_surname'] ?? ''),
                (string) ($values['user_email'] ?? '')
            );
        } else {
            // L'utente di un commerciale non si cambia.
            unset($values['user_id']);
        }
        $values = array_diff_key($values, array_flip(self::USER_FIELDS));

        if (array_key_exists('user_id', $values)) {
            $userId = (int) ($values['user_id'] ?? 0);
            if ($userId <= 0) {
                throw UserError::make('sales_agent.user_required');
            }
            if (!array_key_exists($userId, SalesAgents::userOptions())) {
                throw UserError::make('sales_agent.user_not_agent');
            }

            // Un utente, un commerciale: la visibilità legge una riga sola.
            $self = (int) ($oldValues['id'] ?? 0);
            $taken = sqlCount(SalesAgent::$table, 'user_id = '.$userId." AND deleted = 'false'".($self > 0 ? ' AND id <> '.$self : ''));
            if ((int) $taken > 0) {
                throw UserError::make('sales_agent.user_taken');
            }
            $values['user_id'] = $userId;
        }

        foreach (['commission_percent' => 'Provvigione', 'max_discount_percent' => 'Sconto massimo'] as $key => $label) {
            if (!array_key_exists($key, $values)) {
                continue;
            }
            $value = Numbers::fromForm($values[$key]);
            if ($value !== null && ((float) $value < 0 || (float) $value > 100)) {
                throw UserError::make('sales_agent.invalid_percent', ['field' => $label]);
            }
            $values[$key] = $value ?? '0';
        }

        if (Gestionale::feature('sales_agent_commissions')) {
            static::checkRules(Repeater::rowsFromRequest('commission_rules', (array) $_POST));
        }

        if (Gestionale::feature('sales_agent_targets')) {
            static::checkTargets(Repeater::rowsFromRequest('targets', (array) $_POST));
        }

        $values = static::takePriceLists($values, (int) ($oldValues['id'] ?? 0));
        $values = static::takeNetwork($values, (int) ($oldValues['id'] ?? 0));

        // Il nuovo stato vale subito per la visibilità.
        SalesAgents::forget();

        return $values;
    }

    /**
     * Elenco e predefinito non sono colonne: si tengono da parte e si
     * scrivono dopo. Solo se il form aveva la card (funzionalità accesa).
     *
     * @param array<string, mixed> $values
     * @return array<string, mixed>
     */
    public static function takePriceLists(array $values, int $agentId = 0): array
    {
        static::$priceLists = null;

        if (SalesAgentPriceLists::enabled() && array_key_exists('default_price_list_id', $_POST)) {
            $known = array_keys(static::priceListOptions($agentId));
            $ids = array_values(array_intersect(SalesAgentPriceLists::ids(static::listValue($_POST['price_lists'] ?? ($values['price_lists'] ?? []))), $known));
            $default = (int) ($_POST['default_price_list_id'] ?? 0);

            if ($default > 0 && !in_array($default, $ids, true)) {
                throw UserError::make('sales_agent.default_not_in_list');
            }

            static::$priceLists = ['ids' => $ids, 'default' => $default];
        }

        unset($values['price_lists'], $values['default_price_list_id']);

        return $values;
    }

    /**
     * Rete: niente cerchi sopra di lui, una scelta nota per la modifica. Con
     * la rete spenta i valori salvati restano.
     *
     * @param array<string, mixed> $values
     * @return array<string, mixed>
     */
    public static function takeNetwork(array $values, int $agentId = 0): array
    {
        if (!SalesAgentNetwork::enabled()) {
            unset($values['parent_id'], $values['network_edit']);

            return $values;
        }

        if (array_key_exists('parent_id', $values)) {
            $parent = max(0, (int) $values['parent_id']);
            if (!SalesAgentNetwork::validParent(SalesAgentNetwork::parents(), $agentId, $parent)) {
                throw UserError::make('sales_agent.invalid_parent');
            }
            $values['parent_id'] = $parent;
        }

        if (array_key_exists('network_edit', $values) && !in_array((string) $values['network_edit'], ['default', 'true', 'false'], true)) {
            $values['network_edit'] = 'default';
        }

        return $values;
    }

    /** I commerciali che possono stare sopra quello aperto: non lui, non la sua rete sotto. @return array<int, string> */
    public static function parentOptions(): array
    {
        $id = (int) (static::currentId() ?? 0);
        $current = 0;
        if ($id > 0) {
            $current = (int) (SalesAgentNetwork::parents()[$id] ?? 0);
        }
        $out = [];

        foreach (SalesAgents::options(true, $current) as $option => $label) {
            if (SalesAgentNetwork::validParent(SalesAgentNetwork::parents(), $id, (int) $option)) {
                $out[$option] = $label;
            }
        }

        return $out;
    }

    /** I listini da scegliere: tutti, anche quelli non attivi (si vedono come tali). */
    public static function priceListOptions(int $agentId = 0): array
    {
        $agentId = $agentId > 0 ? $agentId : (int) (static::currentId() ?? 0);
        $options = PriceListResource::options(0, true);

        // Un listino eliminato ma ancora nell'elenco non si perde in silenzio.
        foreach ($agentId > 0 ? SalesAgentPriceLists::of($agentId) : [] as $id) {
            $options[$id] ??= 'Listino #'.$id;
        }

        return $options;
    }

    /** @return list<mixed> */
    private static function listValue(mixed $value): array
    {
        if (is_string($value)) {
            $decoded = json_decode($value, true);

            return is_array($decoded) ? array_values($decoded) : ($value === '' ? [] : [$value]);
        }

        return array_values((array) $value);
    }

    /**
     * Ogni regola ha un prodotto o una categoria (non tutti e due) e una %
     * fra 0 e 100. Le righe vuote si saltano.
     *
     * @param list<array<string, mixed>> $rows
     */
    public static function checkRules(array $rows): void
    {
        foreach (array_values($rows) as $i => $row) {
            $product = (int) ($row['product_id'] ?? 0);
            $category = (int) ($row['category_id'] ?? 0);
            $percent = Numbers::fromForm($row['percent'] ?? null);

            if ($product <= 0 && $category <= 0 && $percent === null) {
                continue;
            }

            if (($product > 0) === ($category > 0) || $percent === null || (float) $percent < 0 || (float) $percent > 100) {
                throw UserError::make('sales_agent.invalid_rule', ['row' => (string) ($i + 1)]);
            }
        }
    }

    /**
     * Le righe degli obiettivi: una riga vuota si salta, le altre vogliono
     * anno, mese (per «Mese») e importo maggiore di 0.
     *
     * @param list<array<string, mixed>> $rows
     */
    public static function checkTargets(array $rows): void
    {
        foreach (array_values($rows) as $i => $row) {
            if (static::emptyTarget($row)) {
                continue;
            }

            $row['amount'] = Numbers::fromForm($row['amount'] ?? null) ?? '';
            if (!Targets::valid($row)) {
                throw UserError::make('sales_agent.invalid_target', ['row' => (string) ($i + 1)]);
            }
        }
    }

    /** @param array<string, mixed> $row */
    private static function emptyTarget(array $row): bool
    {
        return trim((string) ($row['year'] ?? '')) === '' && trim((string) ($row['amount'] ?? '')) === '';
    }

    public static function prepareRepeaterRows(
        string $inputName,
        array $rows,
        string $action = 'store',
        string $context = 'backend'
    ): array {
        if ($inputName === 'targets') {
            $out = [];
            foreach ($rows as $row) {
                if (!is_array($row) || static::emptyTarget($row)) {
                    continue;
                }
                $row = Targets::normalize($row);
                $row['amount'] = Numbers::fromForm($row['amount'] ?? null) ?? '0';
                $out[] = $row;
            }

            return $out;
        }

        if ($inputName !== 'commission_rules') {
            return $rows;
        }

        $out = [];
        foreach ($rows as $row) {
            if (!is_array($row) || ((int) ($row['product_id'] ?? 0) <= 0 && (int) ($row['category_id'] ?? 0) <= 0)) {
                continue;
            }
            $row['product_id'] = (int) ($row['product_id'] ?? 0);
            $row['category_id'] = $row['product_id'] > 0 ? 0 : (int) ($row['category_id'] ?? 0);
            $row['percent'] = Numbers::fromForm($row['percent'] ?? null) ?? '0';
            $out[] = $row;
        }

        return $out;
    }

    public static function mutateFormValues(array $values, string $mode, string $context = 'backend'): array
    {
        $id = (int) ($values['id'] ?? static::currentId() ?? 0);

        if ($id > 0) {
            $values['price_lists'] = array_map('strval', SalesAgentPriceLists::of($id));
            $default = (int) ($values['default_price_list_id'] ?? 0);
            $values['default_price_list_id'] = $default > 0 ? (string) $default : '';
        }

        return parent::mutateFormValues($values, $mode, $context);
    }

    public static function afterStore(object $result, array $values = []): void
    {
        static::savePriceLists((int) ($result->insert_id ?? 0));
    }

    public static function afterUpdate(int|string $id, object $result, array $values = []): void
    {
        static::savePriceLists((int) $id);
    }

    private static function savePriceLists(int $agentId): void
    {
        if ($agentId > 0 && static::$priceLists !== null) {
            SalesAgentPriceLists::assign($agentId, static::$priceLists['ids'], static::$priceLists['default']);
        }

        static::$priceLists = null;
    }
}
