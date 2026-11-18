<?php

namespace Wonder\Plugin\Gestionale\Support\SalesAgents;

use Closure;
use Wonder\App\ResourceSchema\FormField;
use Wonder\Data\UploadSchema as Field;
use Wonder\Elements\Components\Card;
use Wonder\Elements\Components\SectionTitle;
use Wonder\Plugin\Gestionale\Extensions\SettingsSection;
use Wonder\Plugin\Gestionale\Gestionale;
use Wonder\Plugin\Gestionale\Support\Columns;
use Wonder\Sql\TableSchema as Column;

/**
 * Il riquadro «Commerciali» delle Impostazioni (#77): le scelte che si fanno
 * progetto per progetto. Ogni campo c'è solo con la sua funzionalità accesa.
 *
 * - `agent_network_edit`: chi sta sopra modifica i dati della rete sotto
 *   (il valore dei commerciali su «Come da impostazioni»);
 * - `agent_commission_trigger`: la provvigione matura a pagamento o a evasione;
 * - `agent_network_commission_default`: la % di rete di base, per ogni livello;
 * - `agent_network_levels`: le % livello per livello («3, 1»), vuoto = la base;
 * - `agent_target_metric`: su cosa si misurano gli obiettivi.
 */
final class SalesAgentSettings extends SettingsSection
{
    public const TRIGGERS = ['paid' => 'Al pagamento', 'fulfilled' => 'All\'evasione'];
    /** Il campo e la funzionalità che lo mostra. */
    private const FEATURES = [
        'agent_network_edit' => 'sales_agent_network',
        'agent_commission_trigger' => 'sales_agent_commissions',
        'agent_network_commission_default' => 'sales_agent_network_commissions',
        'agent_network_levels' => 'sales_agent_network_commissions',
        'agent_target_metric' => 'sales_agent_targets',
    ];

    public const METRICS = ['revenue' => 'Fatturato', 'orders' => 'Numero di ordini', 'new_customers' => 'Clienti nuovi'];

    public function columns(): array
    {
        return [
            Column::key('agent_network_edit')->enum(['true', 'false'])->default('false'),
            Column::key('agent_commission_trigger')->enum(array_keys(self::TRIGGERS))->default('paid'),
            Columns::decimal('agent_network_commission_default', '5,2'),
            Column::key('agent_network_levels')->type('TEXT'),
            Column::key('agent_target_metric')->enum(array_keys(self::METRICS))->default('revenue'),
        ];
    }

    public function data(): array
    {
        return [
            Field::key('agent_network_edit')->text()->sanitize(false),
            Field::key('agent_commission_trigger')->text()->sanitize(false),
            Field::key('agent_network_commission_default')->number()->decimals(2),
            Field::key('agent_network_levels')->text()->sanitize(false),
            Field::key('agent_target_metric')->text()->sanitize(false),
        ];
    }

    public function fields(): array
    {
        $out = [];

        if (self::on('sales_agent_network')) {
            $out[] = FormField::key('agent_network_edit')->select(['false' => 'No', 'true' => 'Sì'])->value('false')->label('Chi sta sopra modifica la sua rete');
        }

        if (self::on('sales_agent_commissions')) {
            $out[] = FormField::key('agent_commission_trigger')->select(self::TRIGGERS)->value('paid')->label('La provvigione matura');
        }

        if (self::on('sales_agent_network_commissions')) {
            $out[] = FormField::key('agent_network_commission_default')->number()->decimal(2)->label('Provvigione di rete di base (%)');
            $out[] = FormField::key('agent_network_levels')->text()->label('Provvigione di rete per livello (%)');
        }

        if (self::on('sales_agent_targets')) {
            $out[] = FormField::key('agent_target_metric')->select(self::METRICS)->value('revenue')->label('Obiettivi su');
        }

        return $out;
    }

    public function labels(): array
    {
        return [
            'agent_network_edit' => 'Chi sta sopra modifica la sua rete',
            'agent_commission_trigger' => 'La provvigione matura',
            'agent_network_commission_default' => 'Provvigione di rete di base (%)',
            'agent_network_levels' => 'Provvigione di rete per livello (%)',
            'agent_target_metric' => 'Obiettivi su',
        ];
    }

    public function card(Closure $input): Card
    {
        $components = [
            SectionTitle::make('Commerciali')
                ->tooltip('Le regole dei commerciali di questo progetto. Provvigione di rete per livello: le percentuali separate da virgole, dal commerciale subito sopra in su (es. «3, 1»); un livello vuoto o oltre l\'elenco prende la base.')
                ->columnSpan(12),
        ];

        foreach (self::FEATURES as $key => $feature) {
            if (self::on($feature)) {
                $components[] = $input($key)->columnSpan(4);
            }
        }

        return (new Card)->components($components)->columns(12)->columnSpan(12);
    }

    /** Vero se il riquadro ha qualcosa da mostrare. */
    public function shown(): bool
    {
        return $this->fields() !== [];
    }

    public function mutate(array $values): array
    {
        if (!self::on('sales_agent_network')) {
            unset($values['agent_network_edit']);
        } elseif (array_key_exists('agent_network_edit', $values)) {
            $values['agent_network_edit'] = (string) $values['agent_network_edit'] === 'true' ? 'true' : 'false';
        }

        if (!self::on('sales_agent_commissions')) {
            unset($values['agent_commission_trigger']);
        } elseif (array_key_exists('agent_commission_trigger', $values)) {
            $values['agent_commission_trigger'] = isset(self::TRIGGERS[(string) $values['agent_commission_trigger']]) ? (string) $values['agent_commission_trigger'] : 'paid';
        }

        if (!self::on('sales_agent_network_commissions')) {
            unset($values['agent_network_commission_default'], $values['agent_network_levels']);
        } else {
            if (array_key_exists('agent_network_commission_default', $values)) {
                $values['agent_network_commission_default'] = number_format(self::percent($values['agent_network_commission_default']) ?? 0.0, 2, '.', '');
            }

            if (array_key_exists('agent_network_levels', $values)) {
                $values['agent_network_levels'] = self::joinLevels(self::levels((string) $values['agent_network_levels']));
            }
        }

        if (!self::on('sales_agent_targets')) {
            unset($values['agent_target_metric']);
        } elseif (array_key_exists('agent_target_metric', $values)) {
            $values['agent_target_metric'] = isset(self::METRICS[(string) $values['agent_target_metric']]) ? (string) $values['agent_target_metric'] : 'revenue';
        }

        return $values;
    }

    /**
     * Le % di rete livello per livello: «3, 1» ⇒ [1 => 3.0, 2 => 1.0]. Un
     * livello vuoto è `null` (prende la base). Accetta virgola o punto e virgola
     * fra i livelli e la virgola decimale dentro un valore con il punto e virgola.
     *
     * @return array<int, float|null>
     */
    public static function levels(string $text): array
    {
        $text = trim($text);
        if ($text === '') {
            return [];
        }

        $parts = str_contains($text, ';') ? explode(';', $text) : explode(',', $text);
        $out = [];
        foreach ($parts as $i => $part) {
            $out[$i + 1] = self::percent($part);
        }

        while ($out !== [] && end($out) === null) {
            array_pop($out);
        }

        return $out;
    }

    /** @param array<int, float|null> $levels */
    public static function joinLevels(array $levels): string
    {
        return implode('; ', array_map(
            static fn (?float $value): string => $value === null ? '' : rtrim(rtrim(number_format($value, 2, ',', ''), '0'), ','),
            $levels
        ));
    }

    /** Una percentuale fra 0 e 100; `null` se vuota o non è un numero. */
    public static function percent(mixed $value): ?float
    {
        $text = str_replace(',', '.', trim((string) $value));

        if ($text === '' || !is_numeric($text)) {
            return null;
        }

        return round(max(0.0, min(100.0, (float) $text)), 2);
    }

    private static function on(string $feature): bool
    {
        return SalesAgents::enabled() && Gestionale::feature($feature);
    }
}
