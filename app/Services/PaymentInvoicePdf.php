<?php

namespace App\Services;

use App\Models\Payment;

class PaymentInvoicePdf
{
    public function render(Payment $payment): string
    {
        $payment->loadMissing(['customer.branch', 'operator', 'package']);

        $invoiceNumber = sprintf('ZSINV-%s-%06d', $payment->paid_at?->format('Y') ?? now()->format('Y'), $payment->id);
        $customerName = $payment->customer?->name ?? 'Deleted customer';
        $username = $payment->customer?->username ?? 'Unavailable';
        $phone = $payment->customer?->phone ?: 'Not provided';
        $branch = $payment->customer?->branch?->name ?? 'Unassigned';
        $package = $payment->package?->name ?? 'Package';
        $paidAt = $payment->paid_at?->format('d M Y, h:i A') ?? 'Not recorded';
        $method = strtoupper((string) $payment->method);
        $reference = $payment->reference ?: 'Not provided';
        $operator = $payment->operator?->name ?? 'Unknown operator';
        $packageAmount = (float) ($payment->package_amount ?? $payment->amount);
        $ott = (float) ($payment->ott_deduction ?? 0);
        $commission = (float) ($payment->operator_commission ?? 0);
        $amount = (float) $payment->amount;

        $commands = [];
        $commands[] = '0.035 0.286 0.220 rg 0 700 595 142 re f';
        $commands[] = '0.69 0.96 0.35 rg 42 754 46 46 re f';
        $this->text($commands, 55, 770, 18, 'ZS', true, '0.035 0.286 0.220');
        $this->text($commands, 104, 779, 22, 'ZOSTREAM WIFI', true, '1 1 1');
        $this->text($commands, 104, 758, 9, 'INTERNET PAYMENT INVOICE', false, '0.83 0.94 0.89');
        $this->text($commands, 552, 780, 10, 'PAID', true, '0.69 0.96 0.35', 'right');
        $this->text($commands, 552, 760, 11, $invoiceNumber, true, '1 1 1', 'right');

        $this->text($commands, 42, 666, 9, 'BILLED TO', true, '0.18 0.46 0.37');
        $this->text($commands, 42, 642, 18, $customerName, true);
        $this->text($commands, 42, 622, 10, $username.'  |  '.$phone, false, '0.35 0.42 0.39');
        $this->text($commands, 42, 605, 10, 'Branch: '.$branch, false, '0.35 0.42 0.39');

        $this->text($commands, 552, 666, 9, 'PAYMENT DETAILS', true, '0.18 0.46 0.37', 'right');
        $this->text($commands, 552, 644, 10, $paidAt, true, '0.10 0.15 0.13', 'right');
        $this->text($commands, 552, 625, 10, $method.'  |  '.$reference, false, '0.35 0.42 0.39', 'right');
        $this->text($commands, 552, 606, 10, 'Collected by '.$operator, false, '0.35 0.42 0.39', 'right');

        $commands[] = '0.93 0.96 0.95 rg 42 535 511 42 re f';
        $this->text($commands, 58, 551, 9, 'DESCRIPTION', true, '0.25 0.37 0.32');
        $this->text($commands, 537, 551, 9, 'AMOUNT', true, '0.25 0.37 0.32', 'right');
        $this->text($commands, 58, 505, 13, $package, true);
        $this->text($commands, 58, 486, 9, 'Internet package payment', false, '0.42 0.49 0.46');
        $this->text($commands, 537, 500, 12, $this->money($packageAmount), true, '0.10 0.15 0.13', 'right');
        $commands[] = '0.86 0.90 0.88 RG 42 462 511 0.8 re S';

        $this->summaryLine($commands, 404, 'Package amount', $this->money($packageAmount));
        if ($ott > 0) {
            $this->summaryLine($commands, 378, 'OTT reserved', $this->money($ott));
        }
        $this->summaryLine($commands, 352, 'Operator share', $this->money($commission));
        $commands[] = '0.035 0.286 0.220 rg 335 286 218 48 re f';
        $this->text($commands, 351, 303, 10, 'AMOUNT PAID', true, '0.83 0.94 0.89');
        $this->text($commands, 537, 301, 17, $this->money($amount), true, '1 1 1', 'right');

        $commands[] = '0.86 0.90 0.88 RG 42 130 511 0.8 re S';
        $this->text($commands, 42, 102, 10, 'Thank you for choosing ZoStream WiFi.', true, '0.035 0.286 0.220');
        $this->text($commands, 42, 83, 8, 'This computer-generated invoice is valid without a signature.', false, '0.42 0.49 0.46');
        $this->text($commands, 553, 94, 8, config('app.url', ''), false, '0.42 0.49 0.46', 'right');

        return $this->document(implode("\n", $commands));
    }

    private function summaryLine(array &$commands, int $y, string $label, string $value): void
    {
        $this->text($commands, 335, $y, 10, $label, false, '0.35 0.42 0.39');
        $this->text($commands, 537, $y, 10, $value, true, '0.10 0.15 0.13', 'right');
    }

    private function money(float $amount): string
    {
        return 'INR '.number_format($amount, 2);
    }

    private function text(
        array &$commands,
        float $x,
        float $y,
        float $size,
        string $value,
        bool $bold = false,
        string $color = '0.10 0.15 0.13',
        string $align = 'left',
    ): void {
        $value = $this->ascii($value);
        if ($align === 'right') {
            $x -= strlen($value) * $size * 0.51;
        }
        $font = $bold ? 'F2' : 'F1';
        $commands[] = sprintf('BT /%s %.2F Tf %s rg %.2F %.2F Td (%s) Tj ET', $font, $size, $color, $x, $y, $this->escape($value));
    }

    private function ascii(string $value): string
    {
        $converted = function_exists('iconv') ? iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value) : $value;

        return substr(preg_replace('/[^\x20-\x7E]/', '', $converted ?: $value) ?? '', 0, 100);
    }

    private function escape(string $value): string
    {
        return str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $value);
    }

    private function document(string $content): string
    {
        $objects = [
            '<< /Type /Catalog /Pages 2 0 R >>',
            '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
            '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] /Resources << /Font << /F1 5 0 R /F2 6 0 R >> >> /Contents 4 0 R >>',
            "<< /Length ".strlen($content)." >>\nstream\n{$content}\nendstream",
            '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>',
            '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold >>',
        ];

        $pdf = "%PDF-1.4\n";
        $offsets = [0];
        foreach ($objects as $index => $object) {
            $offsets[] = strlen($pdf);
            $pdf .= ($index + 1)." 0 obj\n{$object}\nendobj\n";
        }
        $xref = strlen($pdf);
        $pdf .= "xref\n0 ".(count($objects) + 1)."\n0000000000 65535 f \n";
        foreach (array_slice($offsets, 1) as $offset) {
            $pdf .= sprintf("%010d 00000 n \n", $offset);
        }
        $pdf .= 'trailer << /Size '.(count($objects) + 1)." /Root 1 0 R >>\nstartxref\n{$xref}\n%%EOF";

        return $pdf;
    }
}
