<?php

namespace Wonder\Plugin\Gestionale\Support\Contacts;

use Wonder\App\Dataset\Column;
use Wonder\App\Import\Context;
use Wonder\App\Visibility\RowVisibility;
use Wonder\Plugin\CustomerAccount\Support\Import\{AddressLayout, CompanyLayout, ImportExtension, PeopleLayout, RelationLayout};
use Wonder\Plugin\Gestionale\Models\Contacts\{Company, Contact};
use Wonder\Plugin\Gestionale\Resources\Contacts\{CompanyResource, CustomerResource};
use Wonder\Plugin\Gestionale\Support\SalesAgents\SalesAgentPriceLists;
use Wonder\Plugin\Gestionale\Support\SalesAgents\SalesAgents;

/**
 * I tracciati della rubrica nel gestionale: listino, metodo e condizione di
 * pagamento, commerciale (solo dove il form del cliente li mostra), le schede
 * che chi carica vede e il modo clienti. Il commerciale che carica non sceglie
 * il commerciale: le schede che crea nascono sue, come dal form.
 */
class GestionaleContactsImport extends ImportExtension
{
    /** Colonna ⇒ campo del Model. */
    private const FIELDS = [
        'price_list' => 'price_list_id',
        'payment_method' => 'payment_method_id',
        'payment_term' => 'payment_term_id',
        'agent' => 'agent_id',
    ];

    public function permits(string $layout, object $user): ?bool
    {
        if (!in_array($layout, [PeopleLayout::KEY, CompanyLayout::KEY, AddressLayout::KEY, RelationLayout::KEY], true)) {
            return null;
        }

        // Senza aziende non ci sono né le aziende né i loro referenti.
        if (!CustomerMode::companies() && in_array($layout, [CompanyLayout::KEY, RelationLayout::KEY], true)) {
            return false;
        }

        $resource = in_array($layout, [CompanyLayout::KEY, RelationLayout::KEY], true) ? CompanyResource::class : CustomerResource::class;

        return $resource::allows(RowVisibility::authorities($user), 'update') ? true : null;
    }

    /** Si torna agli elenchi del gestionale, non a quelli della rubrica base. */
    public function backUrl(string $layout): ?string
    {
        $resource = in_array($layout, [CompanyLayout::KEY, RelationLayout::KEY], true) ? CompanyResource::class : CustomerResource::class;

        return $resource::listUrl();
    }

    public function model(string $layout): ?string
    {
        return match ($layout) {
            PeopleLayout::KEY => Contact::class,
            CompanyLayout::KEY => Company::class,
            default => null,
        };
    }

    public function visible(string $layout, array $row): bool
    {
        $table = match ($layout) {
            PeopleLayout::KEY => Contact::$table,
            CompanyLayout::KEY => Company::$table,
            default => null,
        };

        if ($table === null || !ContactVisibility::restricted($table)) {
            return true;
        }

        return ContactVisibility::visibleIds($table, [(int) ($row['id'] ?? 0)]) !== [];
    }

    public function columns(string $layout): array
    {
        $columns = [];

        foreach ($this->options($layout) as $key => $options) {
            $columns[] = Column::make($key, $this->text('columns.'.$key), $this->aliases($key))
                ->field($this->text('fields.'.$key));
        }

        return $columns;
    }

    public function values(string $layout, array $data, ?array $current, Context $context): array
    {
        $resource = $this->resource($layout);

        if ($resource === null) {
            return [];
        }

        $values = [];
        $errors = [];

        $all = $this->options($layout);

        // Il commerciale ritrova fra le scelte il listino già sulla scheda.
        if (isset($all['price_list']) && $current !== null && SalesAgentPriceLists::allowed() !== null) {
            $all['price_list'] = $this->priceListChoices($resource, (int) ($current['price_list_id'] ?? 0));
        }

        foreach ($all as $key => $options) {
            $cell = trim((string) ($data[$key] ?? ''));

            if ($cell === '') {
                continue;
            }

            $id = $this->find($cell, $options);

            if ($id === null) {
                $errors[] = (string) __t('contacts.import.errors.invalid_value', ['field' => $this->text('columns.'.$key), 'value' => $cell]);
                continue;
            }

            // Metodo e condizione vuoti si salvano come stringa vuota, il resto come 0 (come il form).
            $values[self::FIELDS[$key]] = $id > 0 ? (string) $id : (in_array($key, ['payment_method', 'payment_term'], true) ? '' : '0');
        }

        // Chi carica da commerciale non sceglie: la scheda nuova nasce sua.
        $own = SalesAgents::current();
        if ($current === null && $own > 0) {
            $values['agent_id'] = (string) $own;
        }

        // E con il suo listino predefinito, se la colonna è vuota.
        if ($current === null && isset($all['price_list']) && !isset($values['price_list_id'])) {
            $default = $this->newPriceList($resource);
            if ($default > 0) {
                $values['price_list_id'] = (string) $default;
            }
        }

        return ['values' => $values, 'errors' => $errors];
    }

    /**
     * Le colonne del tracciato con le scelte valide (id ⇒ nome), solo quelle
     * che il form del cliente mostra a chi carica.
     *
     * @return array<string, array<int, string>>
     */
    protected function options(string $layout): array
    {
        $resource = $this->resource($layout);

        if ($resource === null) {
            return [];
        }

        $options = [];

        if ($resource::priceListShown()) {
            $options['price_list'] = $resource::priceListChoices();
        }

        if ($resource::paymentDefaultsShown()) {
            $options['payment_method'] = $resource::officeMethods();

            $terms = $resource::activeTerms();
            if (count($terms) > 1) {
                $options['payment_term'] = $terms;
            }
        }

        if ($resource::agentShown()) {
            $options['agent'] = SalesAgents::options(false);
        }

        return $options;
    }

    /** @return class-string<CustomerResource>|null */
    /**
     * Le scelte del listino per una scheda che c'è già.
     *
     * @param class-string<CustomerResource> $resource
     * @return array<int, string>
     */
    protected function priceListChoices(string $resource, int $keep): array
    {
        return $resource::priceListChoices($keep);
    }

    /**
     * Il listino delle schede nuove del commerciale.
     *
     * @param class-string<CustomerResource> $resource
     */
    protected function newPriceList(string $resource): int
    {
        return $resource::newPriceList();
    }

    private function resource(string $layout): ?string
    {
        return match ($layout) {
            PeopleLayout::KEY => CustomerResource::class,
            CompanyLayout::KEY => CompanyResource::class,
            default => null,
        };
    }

    /**
     * L'id per numero o per nome; 0 per «Nessuno», null se non c'è.
     *
     * @param array<int, string> $options
     */
    private function find(string $cell, array $options): ?int
    {
        if (ctype_digit($cell)) {
            $id = (int) $cell;

            return $id === 0 || isset($options[$id]) ? $id : null;
        }

        $needle = $this->plain($cell);

        if (in_array($needle, [$this->plain($this->text('none')), 'nessuno', 'nessuna'], true)) {
            return 0;
        }

        foreach ($options as $id => $name) {
            // Il listino non attivo porta « (non attivo)» nel nome: si riconosce anche senza.
            $plain = $this->plain(html_entity_decode((string) $name, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
            if ($plain === $needle || $plain === $needle.'nonattivo') {
                return (int) $id;
            }
        }

        return null;
    }

    private function plain(string $value): string
    {
        $value = mb_strtolower(trim($value), 'UTF-8');
        $ascii = function_exists('iconv') ? @iconv('UTF-8', 'ASCII//TRANSLIT', $value) : false;

        return (string) preg_replace('/[^a-z0-9]+/', '', $ascii !== false ? strtolower($ascii) : $value);
    }

    /** @return list<string> */
    private function aliases(string $key): array
    {
        return match ($key) {
            'price_list' => ['Listino'],
            'payment_method' => ['Metodo di pagamento', 'Pagamento'],
            'payment_term' => ['Condizione di pagamento', 'Termini di pagamento'],
            'agent' => ['Commerciale', 'Agente'],
            default => [],
        };
    }

    private function text(string $key, array $replace = []): string
    {
        return (string) __t('gestionale.contacts.import.'.$key, $replace);
    }
}
