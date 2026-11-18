<?php

namespace Wonder\Plugin\Gestionale\Support\SalesAgents;

use Throwable;
use Wonder\App\ResourceSchema\TableLayoutSchema;
use Wonder\Plugin\Gestionale\Gestionale;
use Wonder\Plugin\Gestionale\Models\SalesAgents\SalesAgent;
use Wonder\Plugin\Gestionale\Models\Sales\Order;

/**
 * Lo sconto massimo del commerciale (#77, funzionalità `sales_agent_discount_limits`).
 *
 * Conta solo lo sconto scritto a mano (prezzo a mano, sconto di riga, sconto
 * sulla testata), misurato sul prezzo automatico della riga
 * (`reference_price`). Oltre il massimo il documento resta da approvare:
 * non si conferma e non si invia finché l'ufficio non lo approva.
 */
final class DiscountLimit
{
    public static function enabled(): bool
    {
        return SalesAgents::enabled() && Gestionale::feature('sales_agent_discount_limits');
    }

    /**
     * Lo sconto a mano più alto fra le righe, in percentuale.
     *
     * @param list<array<string, mixed>> $lines righe con `reference_price`, `unit_price`, `customization_surcharge`, `quantity`, `order_discount_amount`
     * @param bool $headerManual vero se lo sconto sul totale è quello scritto a mano (non un coupon)
     */
    public static function percent(array $lines, bool $headerManual): float
    {
        $max = 0.0;

        foreach ($lines as $line) {
            $reference = (float) ($line['reference_price'] ?? 0);
            $quantity = (float) ($line['quantity'] ?? 0);

            if ($reference <= 0.0 || $quantity <= 0.0) {
                continue;
            }

            $net = (float) ($line['unit_price'] ?? 0) - (float) ($line['customization_surcharge'] ?? 0);

            if ($headerManual) {
                $net -= (float) ($line['order_discount_amount'] ?? 0) / $quantity;
            }

            $max = max($max, (1 - max(0.0, $net) / $reference) * 100);
        }

        return round(max(0.0, $max), 2);
    }

    /**
     * Il nuovo stato di approvazione dopo un ricalcolo del commerciale.
     *
     * @param float $limit il massimo del commerciale; 0 = nessun limite
     * @param float $approved lo sconto già approvato dall'ufficio
     */
    public static function status(string $current, float $percent, float $limit, float $approved): string
    {
        $over = $limit > 0.0 && $percent > $limit + 0.001;

        if (!$over) {
            return in_array($current, ['pending', 'rejected'], true) ? 'none' : $current;
        }

        if ($current === 'approved' && $percent <= $approved + 0.001) {
            return 'approved';
        }

        return 'pending';
    }

    /** Il massimo del commerciale; 0 se non c'è limite. */
    public static function limitOf(int $agentId): float
    {
        if ($agentId <= 0) {
            return 0.0;
        }

        try {
            $agent = SalesAgent::find(['id' => $agentId]);
        } catch (Throwable $error) {
            error_log('[gestionale] sconto massimo del commerciale '.$agentId.' non letto: '.$error->getMessage());

            return 0.0;
        }

        return is_array($agent) ? max(0.0, (float) ($agent['max_discount_percent'] ?? 0)) : 0.0;
    }

    /**
     * Aggiorna lo stato di approvazione di un preventivo o ordine dopo il
     * ricalcolo. Lo fa scattare solo il commerciale: l'ufficio no.
     *
     * @param array<string, mixed> $order la testata
     * @param list<array<string, mixed>> $lines
     */
    public static function apply(array $order, array $lines, bool $headerManual): void
    {
        if (!self::enabled() || (string) ($order['stage'] ?? '') === 'cart') {
            return;
        }

        $agent = SalesAgents::current();

        if ($agent <= 0) {
            return;
        }

        $current = (string) ($order['approval_status'] ?? 'none');
        $percent = self::percent($lines, $headerManual);
        $next = self::status($current, $percent, self::limitOf($agent), (float) ($order['approval_discount'] ?? 0));

        if ($next === $current && $next !== 'pending') {
            return;
        }

        $values = ['approval_status' => $next];

        if ($next === 'pending') {
            $values['approval_discount'] = number_format($percent, 2, '.', '');
        } elseif ($next === 'none') {
            $values += ['approval_discount' => '0.00', 'approved_by' => 0];
        }

        Order::update($values, (int) $order['id']);
    }

    /** Le etichette dello stato di approvazione, per il filtro e la scheda. */
    public const LABELS = ['pending' => 'Da approvare', 'approved' => 'Approvato', 'rejected' => 'Rifiutato'];

    /** Il filtro «Sconto» negli elenchi di ordini e preventivi. */
    public static function filter(TableLayoutSchema $layout): TableLayoutSchema
    {
        return self::enabled() ? $layout->filterCustom('Sconto', 'approval_status', ['' => 'Tutti'] + self::LABELS) : $layout;
    }

    /** Vero se il documento aspetta l'ufficio o è stato rifiutato. */
    public static function blocks(array $order): bool
    {
        return in_array((string) ($order['approval_status'] ?? 'none'), ['pending', 'rejected'], true);
    }

    /**
     * Le decisioni che l'ufficio può prendere adesso: da approvare ⇒ approva o
     * rifiuta; rifiutato ⇒ si può ancora approvare.
     *
     * @return list<string> `approve`, `reject`
     */
    public static function decisions(array $order, bool $office): array
    {
        if (!$office) {
            return [];
        }

        return match ((string) ($order['approval_status'] ?? 'none')) {
            'pending' => ['approve', 'reject'],
            'rejected' => ['approve'],
            default => [],
        };
    }

    /** Le decisioni per chi sta lavorando: solo l'ufficio, con la funzionalità accesa. @return list<string> */
    public static function decisionsNow(array $order): array
    {
        return self::enabled() ? self::decisions($order, SalesAgents::current() === 0) : [];
    }

    /** L'ufficio approva o rifiuta lo sconto. */
    public static function decide(int $orderId, bool $approve, int $userId): void
    {
        Order::update([
            'approval_status' => $approve ? 'approved' : 'rejected',
            'approved_by' => $userId,
            'approved_at' => date('Y-m-d H:i:s'),
        ], $orderId);
    }
}
