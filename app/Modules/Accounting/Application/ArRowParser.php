<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Application;

use App\Modules\Accounting\Infrastructure\Persistence\Models\ArDocument;

/**
 * Validates the rows of a receivables file against the import contract
 * (docs/product/ACCOUNTING_SOURCES.md). Every problem of every row is
 * reported; nothing is stored unless the whole file is valid.
 */
final class ArRowParser
{
    public const COLUMNS = [
        'record_type', 'document_number', 'related_document', 'customer_code', 'customer_name', 'document_date', 'due_date',
        'currency', 'amount', 'status', 'status_reason', 'collection_owner', 'next_action', 'next_action_date',
    ];

    public const REQUIRED = ['record_type', 'document_number', 'document_date'];

    private const AMOUNT = '/^\d{1,14}(?:\.\d{1,2})?$/';

    private const DATE = '/^\d{4}-\d{2}-\d{2}$/';

    private const CODE = '/^[A-Za-z0-9][A-Za-z0-9._\/-]{0,59}$/';

    /**
     * @param  list<array{line: int, cells: array<string, string>}>  $records
     * @return array{documents: list<array<string, string|int|null>>, errors: list<string>}
     */
    public static function parse(array $records, string $asOf): array
    {
        $documents = [];
        $errors = [];
        $seen = [];

        foreach ($records as $record) {
            $line = $record['line'];
            $cells = array_map(static fn (string $value): string => trim($value), $record['cells']);
            $problems = [];
            $type = mb_strtolower($cells['record_type'] ?? '');

            if ($type === 'credit_note') {
                $errors[] = "Baris {$line}: credit note belum dapat diproses karena aturan perlakuannya belum disetujui.";

                continue;
            }

            if (! in_array($type, [ArDocument::INVOICE, ArDocument::ALLOCATION, ArDocument::TAX_INVOICE], true)) {
                $errors[] = "Baris {$line}: record_type harus invoice, allocation, atau tax_invoice.";

                continue;
            }

            $number = $cells['document_number'] ?? '';
            $related = ($cells['related_document'] ?? '') === '' ? null : $cells['related_document'];

            if (preg_match(self::CODE, $number) !== 1) {
                $problems[] = 'document_number wajib berupa kode tanpa spasi';
            }

            if ($related !== null && preg_match(self::CODE, $related) !== 1) {
                $problems[] = 'related_document harus berupa kode tanpa spasi';
            }

            $date = self::date($cells['document_date'] ?? '', 'document_date', $problems);
            $due = ($cells['due_date'] ?? '') === '' ? null : self::date($cells['due_date'], 'due_date', $problems);
            $nextDate = ($cells['next_action_date'] ?? '') === '' ? null : self::date($cells['next_action_date'], 'next_action_date', $problems);
            $amount = ($cells['amount'] ?? '') === '' ? null : $cells['amount'];
            $currency = ($cells['currency'] ?? '') === '' ? null : strtoupper($cells['currency']);
            $status = ($cells['status'] ?? '') === '' ? null : mb_strtolower($cells['status']);

            if ($date !== null && $date > $asOf) {
                $problems[] = 'document_date setelah tanggal as-of';
            }

            if ($amount !== null && (preg_match(self::AMOUNT, $amount) !== 1 || (float) $amount === 0.0)) {
                $problems[] = 'amount harus angka positif dengan titik desimal (maks. 2 desimal)';
            }

            if ($currency !== null && preg_match('/^[A-Z]{3}$/', $currency) !== 1) {
                $problems[] = 'currency harus kode 3 huruf';
            }

            if ($status !== null && preg_match('/^[a-z][a-z_]{0,39}$/', $status) !== 1) {
                $problems[] = 'status harus kode huruf kecil';
            }

            match ($type) {
                ArDocument::INVOICE => self::invoiceRules($cells, $amount, $currency, $due, $date, $problems),
                ArDocument::ALLOCATION => self::allocationRules($related, $amount, $problems),
                default => self::taxRules($related, $status, $problems),
            };

            $key = $type === ArDocument::ALLOCATION ? "allocation|{$number}|{$related}" : "{$type}|{$number}";

            if (isset($seen[$key])) {
                $problems[] = "duplikat dari baris {$seen[$key]}";
            }

            $seen[$key] = $line;

            if ($problems !== []) {
                $errors[] = "Baris {$line}: ".implode('; ', $problems).'.';

                continue;
            }

            $document = [
                'source_line' => $line,
                'document_type' => $type,
                'document_number' => $number,
                'related_document' => $related,
                'customer_code' => self::optional($cells, 'customer_code', 40),
                'customer_name' => self::optional($cells, 'customer_name', 120),
                'document_date' => $date,
                'due_date' => $due,
                'currency' => $currency,
                'amount' => $amount,
                'status' => $status,
                'status_reason' => self::optional($cells, 'status_reason', 255),
                'collection_owner' => self::optional($cells, 'collection_owner', 120),
                'next_action' => self::optional($cells, 'next_action', 255),
                'next_action_date' => $nextDate,
            ];
            $document['row_hash'] = hash('sha256', json_encode($document, JSON_THROW_ON_ERROR));
            $documents[] = $document;
        }

        $errors = [...$errors, ...self::crossCheck($documents)];

        if ($errors === [] && ! in_array(ArDocument::INVOICE, array_column($documents, 'document_type'), true)) {
            $errors[] = 'File tidak berisi invoice. Snapshot tanpa invoice tidak dapat dibedakan dari file yang salah.';
        }

        return ['documents' => $documents, 'errors' => $errors];
    }

    /**
     * Allocations and tax invoices must point at an invoice of the same file,
     * in its currency, and the file holds one currency.
     *
     * @param  list<array<string, string|int|null>>  $documents
     * @return list<string>
     */
    private static function crossCheck(array $documents): array
    {
        $invoices = [];

        foreach ($documents as $document) {
            if ($document['document_type'] === ArDocument::INVOICE) {
                $invoices[(string) $document['document_number']] = (string) $document['currency'];
            }
        }

        $errors = [];
        $currencies = array_values(array_unique($invoices));

        // Totals, buckets, and control totals add amounts: they never mix
        // currencies, and no conversion rule exists.
        if (count($currencies) > 1) {
            sort($currencies);
            $errors[] = 'Satu snapshot hanya untuk satu mata uang; file ini berisi '.implode(', ', $currencies).'. Pisahkan file per mata uang.';
        }

        foreach ($documents as $document) {
            if ($document['document_type'] === ArDocument::INVOICE) {
                continue;
            }

            $invoice = (string) $document['related_document'];

            if (! array_key_exists($invoice, $invoices)) {
                $errors[] = "Baris {$document['source_line']}: related_document {$invoice} tidak ada sebagai invoice di file ini.";
            } elseif ($document['document_type'] === ArDocument::ALLOCATION && $document['currency'] !== null && $document['currency'] !== $invoices[$invoice]) {
                $errors[] = "Baris {$document['source_line']}: mata uang alokasi berbeda dengan invoice {$invoice}.";
            }
        }

        return $errors;
    }

    /**
     * @param  array<string, string>  $cells
     * @param  list<string>  $problems
     */
    private static function invoiceRules(array $cells, ?string $amount, ?string $currency, ?string $due, ?string $date, array &$problems): void
    {
        if ($amount === null) {
            $problems[] = 'invoice wajib memiliki amount';
        }

        if ($currency === null) {
            $problems[] = 'invoice wajib memiliki currency';
        }

        if (($cells['customer_code'] ?? '') === '') {
            $problems[] = 'invoice wajib memiliki customer_code';
        }

        if ($due === null) {
            $problems[] = 'invoice wajib memiliki due_date';
        } elseif ($date !== null && $due < $date) {
            $problems[] = 'due_date sebelum document_date';
        }
    }

    /**
     * @param  list<string>  $problems
     */
    private static function allocationRules(?string $related, ?string $amount, array &$problems): void
    {
        if ($related === null) {
            $problems[] = 'allocation wajib menyebut invoice pada related_document';
        }

        if ($amount === null) {
            $problems[] = 'allocation wajib memiliki amount';
        }
    }

    /**
     * @param  list<string>  $problems
     */
    private static function taxRules(?string $related, ?string $status, array &$problems): void
    {
        if ($related === null) {
            $problems[] = 'tax_invoice wajib menyebut invoice pada related_document';
        }

        if ($status === null) {
            $problems[] = 'tax_invoice wajib memiliki status dari sistem sumber';
        }
    }

    /**
     * @param  list<string>  $problems
     */
    private static function date(string $value, string $field, array &$problems): ?string
    {
        if (preg_match(self::DATE, $value) !== 1 || ! checkdate((int) substr($value, 5, 2), (int) substr($value, 8, 2), (int) substr($value, 0, 4))) {
            $problems[] = "{$field} harus tanggal YYYY-MM-DD";

            return null;
        }

        return $value;
    }

    /**
     * @param  array<string, string>  $cells
     */
    private static function optional(array $cells, string $field, int $max): ?string
    {
        $value = (string) preg_replace('/[\p{Cc}\p{Cf}]/u', '', $cells[$field] ?? '');

        return $value === '' ? null : mb_substr($value, 0, $max);
    }
}
