# Release Strategy

## 1. Prinsip

- satu immutable artifact dipromosikan dari CI ke staging dan production;
- configuration/secret berbeda per environment dan tidak dibundel ke image;
- migration dirancang forward-compatible dan dijalankan satu kali secara terkontrol;
- setiap release dapat diidentifikasi melalui Git SHA, image digest, migration version, dan configuration version;
- deployment success memerlukan runtime validation, bukan hanya command exit 0.

## 2. Branch dan commit

- branch default: `main`;
- satu commit/push per prompt step dengan format `step-XX: <ringkasan>`;
- perubahan non-step memakai `docs:`, `chore:`, atau `fix:` sesuai konteks;
- dilarang force-push dan rewrite history tanpa instruksi eksplisit;
- setiap push diverifikasi dengan perbandingan SHA lokal dan `origin/main`.

Ketika tim bertambah, branch protection dan pull request review dapat diaktifkan tanpa mengubah requirement traceability.

## 3. Environments

### Local/CI

Ephemeral/reproducible Docker services, synthetic data, safe email/object storage.

### Staging

Topology mendekati production, data terpisah, integration sandbox, full observability, backup/restore rehearsal, dan UAT.

### Production

Private data services, TLS, secret manager, least privilege, backup/DR, monitoring/alert, controlled change window, dan support ownership.

## 4. Deployment sequence

1. Verify approved release scope and sign-off.
2. Verify commit, CI, artifact digest, vulnerability result, configuration diff.
3. Create recovery point/backup per runbook.
4. Deploy backward-compatible code when migration requires expand/contract.
5. Run migration once with lock/duration monitoring.
6. Deploy/replace web, worker, scheduler using the same version.
7. Restart worker safely so old code tidak memproses payload baru.
8. Run health, smoke, role, queue, scheduler, storage, audit, integration freshness checks.
9. Reconcile data/control totals.
10. Continue or rollback based on pre-approved threshold.

## 5. Rollback

- application rollback uses previous immutable image;
- migration rollback is not assumed safe; use forward repair or restore only per reviewed plan;
- preserve evidence/audit of failed deployment;
- queue payload compatibility must be considered when rolling back worker;
- decision and actor are recorded in release report.

## 6. Rollout strategy

1. Pilot core: one team per major group.
2. Wave rollout core to 21 teams after pilot PASS.
3. Add PPIC/AR/QC/production-special and integrations after core adoption.
4. Re-run security, performance, regression, UAT for added scope.
5. Hypercare with measurable exit criteria and owner.

## 7. Release evidence

- release notes and known issues;
- Git SHA/image digest;
- migration/result;
- runtime health/log/metric;
- reconciliation totals;
- test/UAT/sign-off;
- backup/restore status;
- incident/rollback decision;
- version deployed per environment.

