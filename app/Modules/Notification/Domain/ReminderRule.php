<?php

declare(strict_types=1);

namespace App\Modules\Notification\Domain;

use InvalidArgumentException;

/**
 * One signed reminder or escalation rule (DEC-009). A rule exists only as
 * approved configuration; nothing here has a default, and while DEC-009 is
 * open there are no rules and no reminder is sent.
 *
 * - period_deadline: `offset_days` before (negative) or after (positive) a
 *   stage deadline of a period, to the holders of `audience_role` whose unit
 *   lies inside the period's scope (for `employee`: the assessed employees).
 * - corrective_action_due: `offset_days` before (negative) or on (0) the due
 *   date, to the action's owner.
 * - corrective_action_overdue: `offset_days` (≥ 1) after the due date, to the
 *   owner; with `escalate_to_role` also to the holders of that role over the
 *   action's unit.
 */
final readonly class ReminderRule
{
    public const TYPES = ['period_deadline', 'corrective_action_due', 'corrective_action_overdue'];

    private const STAGES = ['target_setting', 'actual_submission', 'verification', 'review', 'calibration', 'final_approval'];

    private const ROLES = ['management', 'department_head', 'team_leader', 'employee', 'hr_performance'];

    private const FIELDS = ['id', 'type', 'signoff_reference', 'offset_days', 'stage', 'audience_role', 'escalate_to_role'];

    private function __construct(
        public string $id,
        public string $type,
        public string $signoffReference,
        public int $offsetDays,
        public ?string $stage,
        public ?string $audienceRole,
        public ?string $escalateToRole,
    ) {}

    /**
     * @param  array<mixed>  $config
     *
     * @throws InvalidArgumentException listing every problem
     */
    public static function fromArray(array $config): self
    {
        $errors = [];

        foreach (array_diff(array_keys($config), self::FIELDS) as $unknown) {
            $errors[] = "Field tidak dikenal: {$unknown}.";
        }

        $id = $config['id'] ?? null;

        if (! is_string($id) || preg_match('/^[a-z0-9][a-z0-9_-]{1,59}$/', $id) !== 1) {
            $errors[] = 'id harus 2–60 karakter huruf kecil, angka, garis bawah, atau tanda hubung.';
        }

        $type = $config['type'] ?? null;

        if (! is_string($type) || ! in_array($type, self::TYPES, true)) {
            $errors[] = 'type tidak dikenal.';
        }

        $signoff = is_string($config['signoff_reference'] ?? null) ? trim($config['signoff_reference']) : '';

        if ($signoff === '' || mb_strlen($signoff) > 255) {
            $errors[] = 'signoff_reference wajib diisi (maks. 255 karakter).';
        }

        $offset = $config['offset_days'] ?? null;

        if (! is_int($offset) || $offset < -60 || $offset > 60) {
            $errors[] = 'offset_days harus bilangan bulat antara -60 dan 60.';
        }

        $stage = $config['stage'] ?? null;
        $audience = $config['audience_role'] ?? null;
        $escalate = $config['escalate_to_role'] ?? null;

        if ($type === 'period_deadline') {
            if (! is_string($stage) || ! in_array($stage, self::STAGES, true)) {
                $errors[] = 'stage wajib diisi dengan tahap deadline periode.';
            }

            if (! is_string($audience) || ! in_array($audience, self::ROLES, true)) {
                $errors[] = 'audience_role wajib diisi dengan role penerima.';
            }

            if ($escalate !== null) {
                $errors[] = 'escalate_to_role tidak berlaku untuk period_deadline.';
            }
        } else {
            if ($stage !== null || $audience !== null) {
                $errors[] = 'stage dan audience_role hanya berlaku untuk period_deadline.';
            }

            if ($type === 'corrective_action_due' && (is_int($offset) && $offset > 0 || $escalate !== null)) {
                $errors[] = 'corrective_action_due memakai offset_days ≤ 0 dan tanpa eskalasi.';
            }

            if ($type === 'corrective_action_overdue' && is_int($offset) && $offset < 1) {
                $errors[] = 'corrective_action_overdue memakai offset_days ≥ 1.';
            }

            if ($escalate !== null && (! is_string($escalate) || ! in_array($escalate, self::ROLES, true))) {
                $errors[] = 'escalate_to_role tidak dikenal.';
            }
        }

        if ($errors !== []) {
            throw new InvalidArgumentException('Aturan pengingat tidak valid: '.implode(' ', $errors));
        }

        /** @var string $id */
        /** @var string $type */
        /** @var int $offset */
        return new self($id, $type, $signoff, $offset, is_string($stage) ? $stage : null, is_string($audience) ? $audience : null, is_string($escalate) ? $escalate : null);
    }

    /**
     * @param  array<mixed>  $rules
     * @return list<self>
     *
     * @throws InvalidArgumentException
     */
    public static function listFrom(array $rules): array
    {
        $parsed = [];
        $ids = [];

        foreach ($rules as $rule) {
            if (! is_array($rule)) {
                throw new InvalidArgumentException('Aturan pengingat tidak valid: setiap aturan harus berupa array.');
            }

            $item = self::fromArray($rule);

            if (isset($ids[$item->id])) {
                throw new InvalidArgumentException("Aturan pengingat tidak valid: id {$item->id} dipakai dua kali.");
            }

            $ids[$item->id] = true;
            $parsed[] = $item;
        }

        return $parsed;
    }
}
