<x-layouts::app :title="__('Dashboard')">
    <div class="flex flex-col gap-6">
        <x-ui.page-header
            :title="__('Dashboard')"
            :description="__('Ringkasan kinerja akan ditampilkan sesuai ruang lingkup akses Anda.')"
        />

        <x-ui.alert variant="info" :title="__('Data KPI belum dikonfigurasi')">
            {{ __('Template KPI, periode, target, dan aktual belum tersedia. Dashboard akan menampilkan data setelah modul KPI diimplementasikan dan keputusan bisnis terkait disetujui.') }}
        </x-ui.alert>

        <x-ui.table-shell :caption="__('Ringkasan KPI periode berjalan')" :columns="[__('KPI'), __('Target'), __('Aktual'), __('Status')]">
            <x-slot:empty>
                <x-ui.empty-state
                    :title="__('Belum ada data KPI')"
                    :description="__('Belum ada scorecard yang dapat ditampilkan.')"
                />
            </x-slot:empty>
        </x-ui.table-shell>
    </div>
</x-layouts::app>
