{{-- Top of every panel page while an import is being checked or saved (AdminPanelProvider): it holds the identity locks (KunciIdentitas) and the rows of the accounts it changes until it ends. --}}
<x-filament::callout
    color="warning"
    icon="heroicon-o-clock"
    heading="Import siswa sedang diproses."
    description="Penyimpanan data siswa, wali murid, guru dan staf, serta akun di layar Pengguna bisa tertunda sampai import selesai. Masuk dan keluar tidak terpengaruh, kecuali akun siswa dan wali yang sedang diubah import."
/>
