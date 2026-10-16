select 'users=' || md5(coalesce(string_agg(t::text, '|' order by t.id), '')) from users t
union all select 'organization_units=' || md5(coalesce(string_agg(t::text, '|' order by t.id), '')) from organization_units t
union all select 'employees=' || md5(coalesce(string_agg(t::text, '|' order by t.id), '')) from employees t
union all select 'role_assignments=' || md5(coalesce(string_agg(t::text, '|' order by t.id), '')) from role_assignments t
union all select 'kpi_definition_versions=' || md5(coalesce(string_agg(t::text, '|' order by t.id), '')) from kpi_definition_versions t
union all select 'stored_object_metadata=' || md5(coalesce(string_agg(t::text, '|' order by t.id), '')) from stored_object_metadata t
union all select 'audit_logs=' || md5(coalesce(string_agg(t::text, '|' order by t.id), '')) from audit_logs t
