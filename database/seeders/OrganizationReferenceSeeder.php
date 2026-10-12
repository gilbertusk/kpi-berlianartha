<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Modules\Organization\Application\OrganizationStructureService;
use App\Modules\Organization\Domain\UnitType;
use App\Modules\Organization\Infrastructure\Persistence\Models\OrganizationUnit;
use App\Modules\Shared\Application\Audit\Actor;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Config;
use RuntimeException;

/**
 * Reference organization structure from PRD §4: one company, three
 * groups/departments, and 21 teams. It creates units only; no KPI, approver,
 * weight, or target is attached. Idempotent: existing codes are left as is.
 */
final class OrganizationReferenceSeeder extends Seeder
{
    public const COMPANY_CODE = 'BAL';

    public const COMPANY_NAME = 'PT Berlian Artha Label';

    /**
     * @var array<string, array{name: string, teams: array<string, string>}>
     */
    public const STRUCTURE = [
        'COMSUP' => [
            'name' => 'Commercial dan Support',
            'teams' => [
                'COMSUP-SALES' => 'Sales',
                'COMSUP-CS' => 'Customer Service',
                'COMSUP-RND-COSTING' => 'R&D Costing',
                'COMSUP-DESIGN-A' => 'Design A',
                'COMSUP-DESIGN-B' => 'Design B',
                'COMSUP-ADMIN-PPIC' => 'Admin PPIC',
                'COMSUP-PURCHASING' => 'Purchasing',
                'COMSUP-HR' => 'HR',
                'COMSUP-GA' => 'GA',
            ],
        ],
        'ACC' => [
            'name' => 'Accounting',
            'teams' => [
                'ACC-ADMIN' => 'Admin Accounting',
                'ACC-AR' => 'Account Receivable',
                'ACC-TAX-INVOICE' => 'Tax Invoice',
            ],
        ],
        'PROD' => [
            'name' => 'Produksi',
            'teams' => [
                'PROD-WOVEN' => 'Woven',
                'PROD-WAREHOUSE' => 'Warehouse',
                'PROD-SCREEN-PRINT' => 'Screen Print',
                'PROD-QC' => 'Quality Control',
                'PROD-PACKING-LOGISTIC' => 'Packing Logistic',
                'PROD-LOGISTIC' => 'Logistic',
                'PROD-HOT-CUT' => 'Hot Cut',
                'PROD-HANG-TAG' => 'Hang Tag',
                'PROD-FLEXO' => 'Flexo Printing',
            ],
        ],
    ];

    public function run(OrganizationStructureService $structure): void
    {
        $effectiveFrom = self::effectiveFrom();
        $actor = Actor::system('seeder', self::class);
        $reason = 'Struktur referensi PRD §4 (seed)';

        $company = $this->ensure($structure, $actor, UnitType::Company, self::COMPANY_CODE, self::COMPANY_NAME, null, $effectiveFrom, $reason);

        foreach (self::STRUCTURE as $departmentCode => $department) {
            $departmentUnit = $this->ensure($structure, $actor, UnitType::Department, $departmentCode, $department['name'], $company->id, $effectiveFrom, $reason);

            foreach ($department['teams'] as $teamCode => $teamName) {
                $this->ensure($structure, $actor, UnitType::Team, $teamCode, $teamName, $departmentUnit->id, $effectiveFrom, $reason);
            }
        }
    }

    public static function effectiveFrom(): string
    {
        $configured = Config::get('organization.reference_effective_from');

        if (is_string($configured) && $configured !== '') {
            return $configured;
        }

        if (App::isProduction()) {
            throw new RuntimeException('Set ORGANIZATION_REFERENCE_EFFECTIVE_FROM (confirmed by HR) before seeding the production structure.');
        }

        return Config::string('organization.reference_effective_from_default');
    }

    private function ensure(
        OrganizationStructureService $structure,
        Actor $actor,
        UnitType $type,
        string $code,
        string $name,
        ?int $parentId,
        string $effectiveFrom,
        string $reason,
    ): OrganizationUnit {
        return OrganizationUnit::query()->where('code', $code)->first()
            ?? $structure->createUnit($actor, $type, $code, $name, $parentId, $effectiveFrom, $reason);
    }
}
