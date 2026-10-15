<template>
  <div class="space-y-6 pb-20 animate-in fade-in slide-in-from-bottom-4 duration-500">
    
    <!-- Floating Toast Notification -->
    <Transition
      enter-active-class="transition duration-300 ease-out transform"
      enter-from-class="opacity-0 translate-y-2 scale-95"
      enter-to-class="opacity-100 translate-y-0 scale-100"
      leave-active-class="transition duration-200 ease-in transform"
      leave-from-class="opacity-100 translate-y-0 scale-100"
      leave-to-class="opacity-0 translate-y-2 scale-95"
    >
      <div 
        v-if="toast.show" 
        class="fixed top-5 right-5 z-[9999] max-w-md p-4 rounded-2xl shadow-xl border flex items-start gap-3 backdrop-blur-md"
        :class="[
          toast.type === 'success' ? 'bg-emerald-50/95 border-emerald-200 text-emerald-900 shadow-emerald-500/10' : '',
          toast.type === 'error' ? 'bg-rose-50/95 border-rose-200 text-rose-900 shadow-rose-500/10' : '',
          toast.type === 'warning' ? 'bg-amber-50/95 border-amber-200 text-amber-900 shadow-amber-500/10' : ''
        ]"
      >
        <i 
          class="pi text-lg shrink-0 mt-0.5"
          :class="[
            toast.type === 'success' ? 'pi-check-circle text-emerald-600' : '',
            toast.type === 'error' ? 'pi-exclamation-circle text-rose-600' : '',
            toast.type === 'warning' ? 'pi-exclamation-triangle text-amber-600' : ''
          ]"
        ></i>
        <div class="flex-1 text-xs font-semibold leading-relaxed">
          {{ toast.message }}
        </div>
        <button @click="toast.show = false" class="text-slate-400 hover:text-slate-600 shrink-0">
          <i class="pi pi-times text-xs"></i>
        </button>
      </div>
    </Transition>

    <!-- Page Header -->
    <div class="flex flex-col sm:flex-row justify-between items-start sm:items-center gap-4">
      <div>
        <div class="flex items-center gap-2.5">
          <h1 class="text-xl sm:text-2xl font-black text-slate-900 tracking-tight">Keamanan & Backup</h1>
          <!-- System ID Verification Badge -->
          <span 
            v-if="systemInfo.system_id_masked" 
            class="hidden sm:inline-flex items-center gap-1.5 px-2.5 py-0.5 rounded-full text-[10px] font-mono font-bold bg-slate-100 text-slate-600 border border-slate-200 shadow-2xs"
            title="System ID aktif di project ini untuk verifikasi keamanan file backup"
          >
            <i class="pi pi-shield text-[9px] text-indigo-500"></i>
            ID: {{ systemInfo.system_id_masked }}
          </span>
        </div>
        <p class="text-xs sm:text-sm text-slate-500 mt-1">Kelola pencadangan otomatis multi-akun Google Drive dan pantau status database.</p>
      </div>

      <button 
        @click="runManualBackup" 
        :disabled="isBackingUp"
        class="inline-flex items-center justify-center gap-2 rounded-xl font-semibold px-5 py-2.5 text-sm transition-all duration-200 active:scale-95 disabled:opacity-50 disabled:pointer-events-none bg-indigo-600 text-white shadow-lg shadow-indigo-600/30 hover:bg-indigo-700 hover:shadow-xl cursor-pointer"
      >
        <i v-if="isBackingUp" class="pi pi-spin pi-spinner text-current"></i>
        <i v-else class="pi pi-cloud-upload text-base"></i>
        <span>{{ isBackingUp ? 'Memproses Backup...' : 'Jalankan Backup Sekarang' }}</span>
      </button>
    </div>

    <!-- Status Overview Cards (Full width grid) -->
    <div v-if="backupStatuses.length > 0" class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
      <div 
        v-for="status in backupStatuses" 
        :key="status.key || status.disk" 
        class="relative overflow-hidden bg-white rounded-2xl border border-slate-200/80 shadow-xs transition-all hover:shadow-md hover:border-indigo-200"
      >
        <div :class="status.type === 'google' || (status.disk && status.disk.includes('google')) ? 'bg-gradient-to-br from-indigo-50/40 via-white to-white' : 'bg-gradient-to-br from-slate-50/50 to-white'" class="p-5 sm:p-6 h-full flex flex-col justify-between">
          <div>
            <!-- Card Header -->
            <div class="flex items-start justify-between gap-3 mb-5">
              <div class="flex items-center gap-3.5 min-w-0">
                <!-- Google Drive Official SVG Icon -->
                <div v-if="status.type === 'google' || (status.disk && status.disk.includes('google'))" class="w-12 h-12 rounded-2xl bg-white border border-slate-200/90 shadow-xs flex items-center justify-center shrink-0 p-2.5">
                  <svg class="w-full h-full" viewBox="0 0 87.3 78" xmlns="http://www.w3.org/2000/svg">
                    <path d="m6.6 66.85 3.85 6.65c.8 1.4 1.95 2.5 3.3 3.3l13.75-23.8h-27.5c0 1.55.4 3.1 1.2 4.5z" fill="#0066da"/>
                    <path d="m43.65 25-13.75-23.8c-1.35.8-2.5 1.9-3.3 3.3l-25.4 44a9.06 9.06 0 0 0 -1.2 4.5h27.5z" fill="#00ac47"/>
                    <path d="m73.55 76.8c1.35-.8 2.5-1.9 3.3-3.3l1.6-2.75 7.65-13.25c.8-1.4 1.2-2.95 1.2-4.5h-27.502l5.852 11.5z" fill="#ea4335"/>
                    <path d="m43.65 25 13.75-23.8c-1.35-.8-2.9-1.2-4.5-1.2h-18.5c-1.6 0-3.15.45-4.5 1.2z" fill="#00832d"/>
                    <path d="m59.8 53h-32.3l-13.75 23.8c1.35.8 2.9 1.2 4.5 1.2h50.8c1.6 0 3.15-.45 4.5-1.2z" fill="#ffba00"/>
                    <path d="m73.4 26.5-12.7-22c-.8-1.4-1.95-2.5-3.3-3.3l-13.75 23.8 16.15 28h27.45c0-1.55-.4-3.1-1.2-4.5z" fill="#2684fc"/>
                  </svg>
                </div>
                <!-- Server Database Icon -->
                <div v-else class="w-12 h-12 rounded-2xl bg-slate-800 shadow-xs flex items-center justify-center text-white shrink-0">
                  <i class="pi pi-database text-xl"></i>
                </div>

                <div class="min-w-0">
                  <h3 class="font-bold text-slate-800 text-sm tracking-tight">
                    {{ status.type === 'google' || (status.disk && status.disk.includes('google')) ? 'Google Drive' : 'Server Lokal' }}
                  </h3>
                  <!-- Small email below Google Drive title -->
                  <p 
                    v-if="(status.type === 'google' || (status.disk && status.disk.includes('google'))) && (status.email || getDiskEmail(status.disk || status.key))" 
                    class="text-[11px] font-mono text-slate-500 truncate max-w-[180px] sm:max-w-[210px] mt-0.5" 
                    :title="status.email || getDiskEmail(status.disk || status.key)"
                  >
                    {{ status.email || getDiskEmail(status.disk || status.key) }}
                  </p>
                  <p class="text-[11px] font-medium text-slate-400 mt-0.5">
                    {{ status.amount }} File Backup
                  </p>
                </div>
              </div>

              <!-- Badge Status -->
              <span 
                class="inline-flex items-center px-2.5 py-0.5 rounded-full text-[10px] font-bold uppercase tracking-wider shadow-2xs shrink-0"
                :class="[
                  status.is_healthy ? 'bg-emerald-50 text-emerald-600 border border-emerald-100' : 
                  (status.amount > 0 ? 'bg-slate-100 text-slate-500' : 
                  ((status.type === 'google' || (status.disk && status.disk.includes('google'))) ? 'bg-amber-50 text-amber-600 border border-amber-100' : 'bg-slate-100 text-slate-500'))
                ]"
              >
                {{ status.is_healthy ? 'AKTIF' : (status.amount > 0 ? 'Tersedia' : ((status.type === 'google' || (status.disk && status.disk.includes('google'))) ? 'Perlu Otorisasi' : 'BELUM ADA')) }}
              </span>
            </div>
            
            <!-- Details Box -->
            <div class="space-y-3 bg-white/80 p-3.5 rounded-xl border border-slate-100 shadow-2xs">
              <div class="flex items-center justify-between gap-2 text-xs">
                <span class="flex items-center gap-1.5 text-slate-500">
                  <i class="pi pi-history text-slate-400"></i> Terakhir
                </span>
                <span class="font-bold text-slate-700 text-right">
                  {{ status.newest_backup_date || 'Belum ada' }}
                </span>
              </div>
              <div class="flex items-center justify-between gap-2 text-xs border-t border-slate-50 pt-2">
                <span class="flex items-center gap-1.5 text-slate-500">
                  <i class="pi pi-server text-slate-400"></i> Kapasitas
                </span>
                <span class="font-bold text-slate-700 font-mono">
                  {{ status.used_storage }}
                </span>
              </div>
            </div>
          </div>

          <!-- Action for Google Drive Disks -->
          <div v-if="status.type === 'google' || (status.disk && status.disk.includes('google'))" class="mt-4 pt-3 border-t border-slate-100/80">
            <a 
              :href="status.folder_id ? `https://drive.google.com/drive/folders/${status.folder_id}` : getDiskDriveUrl(status.disk || status.key)" 
              target="_blank" 
              class="w-full inline-flex justify-center items-center gap-2 py-2 px-3 bg-white hover:bg-slate-50 text-indigo-600 text-xs font-bold rounded-xl border border-slate-200 transition-colors shadow-2xs"
            >
              <i class="pi pi-external-link"></i> Buka Google Drive
            </a>
          </div>
        </div>
      </div>
    </div>

    <!-- 1-Click Multi-Account Google Drive Integration Card (Full width) -->
    <div class="bg-white rounded-2xl border border-slate-200 shadow-xs overflow-hidden">
      <div class="p-6 border-b border-slate-100 bg-slate-50/50 flex flex-col sm:flex-row justify-between sm:items-center gap-4">
        <div class="flex items-center gap-3.5">
          <div class="w-10 h-10 rounded-xl bg-white border border-slate-200 shadow-2xs flex items-center justify-center p-2 shrink-0">
            <svg class="w-full h-full" viewBox="0 0 87.3 78" xmlns="http://www.w3.org/2000/svg">
              <path d="m6.6 66.85 3.85 6.65c.8 1.4 1.95 2.5 3.3 3.3l13.75-23.8h-27.5c0 1.55.4 3.1 1.2 4.5z" fill="#0066da"/>
              <path d="m43.65 25-13.75-23.8c-1.35.8-2.5 1.9-3.3 3.3l-25.4 44a9.06 9.06 0 0 0 -1.2 4.5h27.5z" fill="#00ac47"/>
              <path d="m73.55 76.8c1.35-.8 2.5-1.9 3.3-3.3l1.6-2.75 7.65-13.25c.8-1.4 1.2-2.95 1.2-4.5h-27.502l5.852 11.5z" fill="#ea4335"/>
              <path d="m43.65 25 13.75-23.8c-1.35-.8-2.9-1.2-4.5-1.2h-18.5c-1.6 0-3.15.45-4.5 1.2z" fill="#00832d"/>
              <path d="m59.8 53h-32.3l-13.75 23.8c1.35.8 2.9 1.2 4.5 1.2h50.8c1.6 0 3.15-.45 4.5-1.2z" fill="#ffba00"/>
              <path d="m73.4 26.5-12.7-22c-.8-1.4-1.95-2.5-3.3-3.3l-13.75 23.8 16.15 28h27.45c0-1.55-.4-3.1-1.2-4.5z" fill="#2684fc"/>
            </svg>
          </div>
          <div>
            <h2 class="font-bold text-slate-800 text-base">Penyimpanan Google Drive</h2>
            <p class="text-xs text-slate-500">Pusat penyimpanan file cadangan (backup) otomatis berbasis cloud dengan dukungan multi-akun simultan tanpa batas.</p>
          </div>
        </div>
        <div class="flex items-center gap-2 self-start sm:self-auto">
          <span 
            class="inline-flex items-center px-2.5 py-0.5 rounded-full text-[10px] font-bold uppercase tracking-wider shadow-2xs"
            :class="isConnected ? 'bg-emerald-50 text-emerald-600 border border-emerald-100' : 'bg-slate-100 text-slate-500'"
          >
            <span class="inline-flex items-center gap-1.5">
              <span class="w-2 h-2 rounded-full" :class="isConnected ? 'bg-emerald-500 animate-pulse' : 'bg-slate-400'"></span>
              {{ isConnected ? `${accounts.length} Akun Terhubung` : 'BELUM TERHUBUNG' }}
            </span>
          </span>
          <button 
            v-if="isConnected"
            @click="connectGoogleDrive" 
            :disabled="isConnecting"
            class="inline-flex items-center gap-1.5 px-3.5 py-1.5 bg-indigo-50 hover:bg-indigo-100 text-indigo-700 text-xs font-bold rounded-xl border border-indigo-200 transition-colors shadow-2xs cursor-pointer disabled:opacity-50"
          >
            <i class="pi pi-plus text-xs"></i> Tambah Akun
          </button>
        </div>
      </div>

      <div class="p-6">
        <!-- State 1: Belum Ada Akun yang Terhubung -->
        <div v-if="!isConnected" class="flex flex-col items-center justify-center py-8 text-center">
          <div class="w-16 h-16 rounded-2xl bg-white border border-slate-200 flex items-center justify-center mb-4 shadow-xs p-3.5">
            <svg class="w-full h-full" viewBox="0 0 87.3 78" xmlns="http://www.w3.org/2000/svg">
              <path d="m6.6 66.85 3.85 6.65c.8 1.4 1.95 2.5 3.3 3.3l13.75-23.8h-27.5c0 1.55.4 3.1 1.2 4.5z" fill="#0066da"/>
              <path d="m43.65 25-13.75-23.8c-1.35.8-2.5 1.9-3.3 3.3l-25.4 44a9.06 9.06 0 0 0 -1.2 4.5h27.5z" fill="#00ac47"/>
              <path d="m73.55 76.8c1.35-.8 2.5-1.9 3.3-3.3l1.6-2.75 7.65-13.25c.8-1.4 1.2-2.95 1.2-4.5h-27.502l5.852 11.5z" fill="#ea4335"/>
              <path d="m43.65 25 13.75-23.8c-1.35-.8-2.9-1.2-4.5-1.2h-18.5c-1.6 0-3.15.45-4.5 1.2z" fill="#00832d"/>
              <path d="m59.8 53h-32.3l-13.75 23.8c1.35.8 2.9 1.2 4.5 1.2h50.8c1.6 0 3.15-.45 4.5-1.2z" fill="#ffba00"/>
              <path d="m73.4 26.5-12.7-22c-.8-1.4-1.95-2.5-3.3-3.3l-13.75 23.8 16.15 28h27.45c0-1.55-.4-3.1-1.2-4.5z" fill="#2684fc"/>
            </svg>
          </div>
          <h3 class="text-base font-bold text-slate-800 mb-1">Hubungkan Akun Google Drive</h3>
          <p class="text-sm text-slate-500 max-w-md mb-6 leading-relaxed">
            Hubungkan akun Google untuk menyimpan data cadangan secara otomatis. Anda dapat menghubungkan beberapa akun sekaligus untuk redundansi data tanpa batas.
          </p>

          <div v-if="!isConfigured" class="mb-5 p-3.5 bg-amber-50 border border-amber-200 rounded-xl text-amber-800 text-xs max-w-md text-left flex items-start gap-2.5">
            <i class="pi pi-exclamation-triangle text-amber-600 mt-0.5 shrink-0"></i>
            <div>
              <strong>Kredensial Developer Belum Diatur:</strong>
              <p class="mt-0.5 text-amber-700 leading-normal">
                Hubungi developer untuk mengatur kredensial developer Google Drive di file .env server.
              </p>
            </div>
          </div>

          <button 
            @click="connectGoogleDrive" 
            :disabled="isConnecting || !isConfigured"
            class="inline-flex items-center gap-3 px-6 py-3 bg-white hover:bg-slate-50 text-slate-700 text-sm font-bold rounded-xl border border-slate-300 shadow-xs hover:shadow transition-all disabled:opacity-50 disabled:cursor-not-allowed cursor-pointer"
          >
            <svg class="w-5 h-5" viewBox="0 0 87.3 78" xmlns="http://www.w3.org/2000/svg">
              <path d="m6.6 66.85 3.85 6.65c.8 1.4 1.95 2.5 3.3 3.3l13.75-23.8h-27.5c0 1.55.4 3.1 1.2 4.5z" fill="#0066da"/>
              <path d="m43.65 25-13.75-23.8c-1.35.8-2.5 1.9-3.3 3.3l-25.4 44a9.06 9.06 0 0 0 -1.2 4.5h27.5z" fill="#00ac47"/>
              <path d="m73.55 76.8c1.35-.8 2.5-1.9 3.3-3.3l1.6-2.75 7.65-13.25c.8-1.4 1.2-2.95 1.2-4.5h-27.502l5.852 11.5z" fill="#ea4335"/>
              <path d="m43.65 25 13.75-23.8c-1.35-.8-2.9-1.2-4.5-1.2h-18.5c-1.6 0-3.15.45-4.5 1.2z" fill="#00832d"/>
              <path d="m59.8 53h-32.3l-13.75 23.8c1.35.8 2.9 1.2 4.5 1.2h50.8c1.6 0 3.15-.45 4.5-1.2z" fill="#ffba00"/>
              <path d="m73.4 26.5-12.7-22c-.8-1.4-1.95-2.5-3.3-3.3l-13.75 23.8 16.15 28h27.45c0-1.55-.4-3.1-1.2-4.5z" fill="#2684fc"/>
            </svg>
            <span>{{ isConnecting ? 'Membuka Google...' : 'Hubungkan dengan Google Drive' }}</span>
          </button>

          <!-- Privacy Guarantee Note -->
          <div class="mt-4 flex items-center gap-2.5 text-xs text-slate-500 bg-slate-50 px-4 py-2.5 rounded-xl border border-slate-200/80 max-w-md text-left">
            <i class="pi pi-shield text-emerald-600 text-sm shrink-0"></i>
            <span class="leading-relaxed"><strong>Privasi Terjamin:</strong> Sistem hanya mengelola file backup miliknya sendiri, tanpa dapat membaca atau mengakses dokumen/foto pribadi klien lainnya.</span>
          </div>
        </div>

        <!-- State 2: Sudah Ada Akun yang Terhubung (Multi-Account List) -->
        <div v-else class="space-y-6">
          <!-- Multi-Account Notice -->
          <div class="p-4 bg-indigo-50/70 border border-indigo-100 rounded-xl flex items-start gap-3 text-xs text-indigo-900 leading-relaxed">
            <i class="pi pi-info-circle text-indigo-600 mt-0.5 text-sm shrink-0"></i>
            <div>
              <strong>Pencadangan Multi-Akun Aktif:</strong> Setiap kali backup otomatis atau manual dijalankan, data cadangan akan otomatis diunggah secara bersamaan ke seluruh <strong>{{ accounts.length }} akun</strong> di bawah ini untuk menjamin redundansi data jika salah satu akun penuh atau terjadi kendala.
            </div>
          </div>

          <!-- List of Connected Accounts -->
          <div class="space-y-4">
            <div 
              v-for="(acc, index) in accounts" 
              :key="acc.id || index" 
              class="p-5 bg-white border border-slate-200 rounded-2xl shadow-2xs transition-all hover:border-slate-300 space-y-4"
            >
              <!-- Account Header Row -->
              <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
                <div class="flex items-center gap-3.5">
                  <!-- Google Profile Photo -->
                  <img 
                    v-if="acc.avatar && !avatarErrors[acc.id]" 
                    :src="acc.avatar" 
                    referrerpolicy="no-referrer"
                    @error="avatarErrors[acc.id] = true"
                    alt="" 
                    class="w-12 h-12 rounded-full object-cover shadow-2xs shrink-0 border-2 border-indigo-100 ring-2 ring-slate-100" 
                  />
                  <div 
                    v-else 
                    class="w-12 h-12 rounded-full bg-gradient-to-br from-indigo-500 to-indigo-600 text-white flex items-center justify-center font-bold text-base shadow-2xs shrink-0"
                  >
                    {{ acc.email ? acc.email.charAt(0).toUpperCase() : (index + 1) }}
                  </div>

                  <div>
                    <div class="flex items-center gap-2 flex-wrap">
                      <h4 class="font-bold text-slate-800 text-sm">{{ acc.name || 'Akun Google' }}</h4>
                      <span class="inline-flex items-center gap-1 text-[11px] font-semibold text-emerald-700 bg-emerald-100/80 px-2 py-0.5 rounded-full">
                        <i class="pi pi-check-circle text-[10px]"></i> Aktif
                      </span>
                    </div>
                    <p class="text-xs font-mono text-slate-600 mt-0.5">{{ acc.email }}</p>
                    <p class="text-[11px] text-slate-400 mt-0.5">Terhubung sejak: {{ acc.connected_at || '-' }}</p>
                  </div>
                </div>

                <div class="flex items-center gap-2 self-end sm:self-auto">
                  <a 
                    :href="getDriveUrl(acc)" 
                    target="_blank" 
                    class="inline-flex items-center gap-1.5 px-3 py-2 bg-slate-50 hover:bg-slate-100 text-indigo-600 text-xs font-bold rounded-lg border border-slate-200 transition-colors shadow-2xs"
                  >
                    <i class="pi pi-external-link"></i> Buka Drive
                  </a>
                  <button 
                    @click="confirmDisconnect(acc)" 
                    class="inline-flex items-center gap-1.5 px-3 py-2 bg-white hover:bg-rose-50 text-rose-600 hover:text-rose-700 text-xs font-bold rounded-lg border border-rose-200 transition-colors shadow-2xs cursor-pointer"
                  >
                    <i class="pi pi-power-off"></i> Putuskan
                  </button>
                </div>
              </div>

              <!-- Folder ID Settings (Optional) for this specific account -->
              <div class="pt-3 border-t border-slate-100">
                <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-3 bg-slate-50/80 p-3 rounded-xl border border-slate-100">
                  <div class="space-y-1 flex-1">
                    <label class="block text-[11px] font-bold text-slate-700 uppercase tracking-wide">
                      Folder ID Google Drive <span class="text-slate-400 font-normal lowercase">(opsional untuk akun ini)</span>
                    </label>
                    <div class="relative max-w-md">
                      <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                        <i class="pi pi-folder text-slate-400 text-xs"></i>
                      </div>
                      <input 
                        v-model="folderInputs[acc.id]" 
                        type="text" 
                        placeholder="Contoh: 1A2b3C4d5E... (Kosongkan untuk folder root)" 
                        class="w-full pl-9 pr-3 py-1.5 bg-white border border-slate-200 rounded-lg focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500 text-xs font-mono transition-all outline-none" 
                      />
                    </div>
                  </div>
                  <div class="self-end sm:self-center">
                    <button 
                      @click="saveFolderId(acc)" 
                      :disabled="isSavingFolder[acc.id]"
                      class="inline-flex items-center gap-1.5 px-3.5 py-2 bg-indigo-600 hover:bg-indigo-700 text-white text-xs font-bold rounded-lg shadow-2xs transition-all disabled:opacity-50 cursor-pointer"
                    >
                      <i :class="isSavingFolder[acc.id] ? 'pi pi-spin pi-spinner' : 'pi pi-save'"></i>
                      <span>Simpan</span>
                    </button>
                  </div>
                </div>
              </div>
            </div>
          </div>

          <!-- Connect Additional Account Section -->
          <div class="p-5 border-2 border-dashed border-slate-200 hover:border-indigo-300 rounded-2xl flex flex-col sm:flex-row items-center justify-between gap-4 bg-slate-50/40 transition-colors">
            <div>
              <h4 class="text-sm font-bold text-slate-800">Ingin mencadangkan ke akun Google Drive lain?</h4>
              <p class="text-xs text-slate-500 mt-0.5">
                Tambahkan akun Google Drive tambahan (misal: akun cadangan/klien) agar data otomatis terduplikasi secara aman.
              </p>
            </div>
            <button 
              @click="connectGoogleDrive" 
              :disabled="isConnecting"
              class="inline-flex items-center gap-2.5 px-4 py-2.5 bg-white hover:bg-slate-50 text-slate-700 text-xs font-bold rounded-xl border border-slate-300 shadow-xs transition-all cursor-pointer disabled:opacity-50 shrink-0"
            >
              <svg class="w-4 h-4" viewBox="0 0 87.3 78" xmlns="http://www.w3.org/2000/svg">
                <path d="m6.6 66.85 3.85 6.65c.8 1.4 1.95 2.5 3.3 3.3l13.75-23.8h-27.5c0 1.55.4 3.1 1.2 4.5z" fill="#0066da"/>
                <path d="m43.65 25-13.75-23.8c-1.35.8-2.5 1.9-3.3 3.3l-25.4 44a9.06 9.06 0 0 0 -1.2 4.5h27.5z" fill="#00ac47"/>
                <path d="m73.55 76.8c1.35-.8 2.5-1.9 3.3-3.3l1.6-2.75 7.65-13.25c.8-1.4 1.2-2.95 1.2-4.5h-27.502l5.852 11.5z" fill="#ea4335"/>
                <path d="m43.65 25 13.75-23.8c-1.35-.8-2.9-1.2-4.5-1.2h-18.5c-1.6 0-3.15.45-4.5 1.2z" fill="#00832d"/>
                <path d="m59.8 53h-32.3l-13.75 23.8c1.35.8 2.9 1.2 4.5 1.2h50.8c1.6 0 3.15-.45 4.5-1.2z" fill="#ffba00"/>
                <path d="m73.4 26.5-12.7-22c-.8-1.4-1.95-2.5-3.3-3.3l-13.75 23.8 16.15 28h27.45c0-1.55-.4-3.1-1.2-4.5z" fill="#2684fc"/>
              </svg>
              <span>{{ isConnecting ? 'Membuka Google...' : '+ Hubungkan Akun Google Drive Tambahan' }}</span>
            </button>
          </div>
        </div>
      </div>
    </div>

    <!-- Schedule Config -->
    <div class="bg-white rounded-2xl border border-slate-200 shadow-xs overflow-hidden">
      <div class="p-6 border-b border-slate-100 bg-slate-50/50 flex justify-between items-center">
        <div>
          <h2 class="font-bold text-slate-800 flex items-center gap-2">
            <i class="pi pi-calendar text-indigo-500"></i> Jadwal Backup Otomatis
          </h2>
          <p class="text-xs text-slate-500 mt-1">Sistem akan secara otomatis mencadangkan data Anda sesuai dengan jadwal yang Anda tentukan di bawah ini.</p>
        </div>
      </div>
      
      <div class="p-6 bg-white space-y-6">
        <div class="grid grid-cols-1 md:grid-cols-12 gap-6 items-end">
          <!-- Frequency Selector -->
          <div class="md:col-span-4">
            <label class="block text-sm font-semibold text-slate-700 mb-2">Frekuensi Backup</label>
            <div class="relative">
              <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                <i class="pi pi-sync text-slate-400"></i>
              </div>
              <select v-model="scheduleForm.frequency" class="w-full pl-10 pr-4 py-2.5 bg-slate-50 border border-slate-200 rounded-xl focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500 text-sm font-medium text-slate-700 transition-all cursor-pointer appearance-none">
                <option value="daily">Harian (Setiap Hari)</option>
                <option value="weekly">Mingguan (Setiap Minggu)</option>
                <option value="monthly">Bulanan (Setiap Bulan)</option>
              </select>
              <div class="absolute inset-y-0 right-0 pr-3 flex items-center pointer-events-none">
                <i class="pi pi-chevron-down text-slate-400 text-xs"></i>
              </div>
            </div>
          </div>
          
          <!-- Conditional Fields based on Frequency -->
          <div class="md:col-span-4 transition-all duration-300" v-if="scheduleForm.frequency !== 'daily'">
            <label class="block text-sm font-semibold text-slate-700 mb-2">
              {{ scheduleForm.frequency === 'weekly' ? 'Pilih Hari' : 'Pilih Tanggal' }}
            </label>
            <div class="relative">
              <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                <i :class="scheduleForm.frequency === 'weekly' ? 'pi pi-calendar-times' : 'pi pi-calendar-plus'" class="text-slate-400"></i>
              </div>
              <!-- Weekly Selector -->
              <select v-if="scheduleForm.frequency === 'weekly'" v-model="scheduleForm.day" class="w-full pl-10 pr-4 py-2.5 bg-slate-50 border border-slate-200 rounded-xl focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500 text-sm font-medium text-slate-700 transition-all cursor-pointer appearance-none">
                <option :value="1">Senin</option>
                <option :value="2">Selasa</option>
                <option :value="3">Rabu</option>
                <option :value="4">Kamis</option>
                <option :value="5">Jumat</option>
                <option :value="6">Sabtu</option>
                <option :value="0">Minggu</option>
              </select>
              <!-- Monthly Selector -->
              <select v-if="scheduleForm.frequency === 'monthly'" v-model="scheduleForm.date" class="w-full pl-10 pr-4 py-2.5 bg-slate-50 border border-slate-200 rounded-xl focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500 text-sm font-medium text-slate-700 transition-all cursor-pointer appearance-none">
                <option v-for="d in 31" :key="d" :value="d">Tanggal {{ d }}</option>
              </select>
              <div class="absolute inset-y-0 right-0 pr-3 flex items-center pointer-events-none">
                <i class="pi pi-chevron-down text-slate-400 text-xs"></i>
              </div>
            </div>
          </div>

          <!-- Time Selector -->
          <div class="md:col-span-4">
            <label class="block text-sm font-semibold text-slate-700 mb-2">Pukul (Waktu WIB)</label>
            <div class="relative">
              <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                <i class="pi pi-clock text-slate-400"></i>
              </div>
              <input type="time" v-model="scheduleForm.time" class="w-full pl-10 pr-4 py-2 bg-slate-50 border border-slate-200 rounded-xl focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500 text-sm font-medium text-slate-700 transition-all outline-none" />
            </div>
          </div>
        </div>

        <div class="flex justify-end pt-4 border-t border-slate-100">
          <button 
            @click="saveSchedule" 
            :disabled="isSavingSchedule"
            class="inline-flex items-center justify-center gap-2 rounded-xl font-semibold px-5 py-2.5 text-sm transition-all duration-200 active:scale-95 disabled:opacity-50 disabled:pointer-events-none bg-indigo-600 text-white shadow-lg shadow-indigo-600/30 hover:bg-indigo-700 hover:shadow-xl cursor-pointer"
          >
            <i :class="isSavingSchedule ? 'pi pi-spin pi-spinner' : 'pi pi-save'"></i>
            <span>Simpan Jadwal Backup</span>
          </button>
        </div>
      </div>
    </div>

    <!-- Restore Section -->
    <div class="bg-rose-50/50 rounded-2xl border border-rose-100 overflow-hidden">
      <div class="p-6 border-b border-rose-100 bg-rose-50/80 flex justify-between items-center">
        <div>
          <h2 class="font-bold text-rose-800 flex items-center gap-2">
            <i class="pi pi-history text-rose-500"></i> Restore Database
          </h2>
          <p class="text-xs text-rose-600/80 mt-1">Kembalikan data sistem ke versi sebelumnya menggunakan file backup (.zip).</p>
        </div>
      </div>
      <div class="p-6 bg-white flex flex-col md:flex-row gap-6 items-center justify-between">
        <div class="w-full md:w-2/3">
          <div class="flex items-center justify-center w-full">
            <label for="dropzone-file" class="flex flex-col items-center justify-center w-full h-32 border-2 border-slate-300 border-dashed rounded-xl cursor-pointer bg-slate-50 hover:bg-slate-100 transition-colors" :class="{'border-indigo-500 bg-indigo-50': selectedFile}">
              <div class="flex flex-col items-center justify-center pt-5 pb-6">
                <i class="pi pi-cloud-upload text-3xl mb-3 text-slate-400" :class="{'text-indigo-500': selectedFile}"></i>
                <p class="mb-1 text-sm text-slate-500" v-if="!selectedFile"><span class="font-semibold">Klik untuk upload</span> atau tarik file ke sini</p>
                <p class="text-sm font-bold text-indigo-600" v-else>{{ selectedFile.name }}</p>
                <p class="text-xs text-slate-500">Hanya menerima file .zip backup sistem ini</p>
              </div>
              <input id="dropzone-file" type="file" class="hidden" accept=".zip" @change="handleFileSelect" />
            </label>
          </div>
        </div>
        <div class="w-full md:w-1/3 flex flex-col gap-3">
          <div class="p-3 bg-amber-50 border border-amber-200 rounded-lg text-[10px] text-amber-800 font-medium leading-relaxed">
            <i class="pi pi-exclamation-triangle mr-1"></i> <strong>PERINGATAN:</strong> Melakukan restore akan menghapus seluruh data saat ini secara permanen dan menimpanya dengan data dari file backup.
          </div>
          <button @click="confirmRestore" :disabled="!selectedFile || isRestoring" class="w-full inline-flex justify-center items-center gap-2 py-2.5 px-4 bg-rose-600 hover:bg-rose-700 text-white text-sm font-bold rounded-xl transition-colors shadow-lg shadow-rose-200 disabled:opacity-50 disabled:cursor-not-allowed cursor-pointer">
            <i :class="isRestoring ? 'pi pi-spin pi-spinner' : 'pi pi-refresh'"></i>
            {{ isRestoring ? 'Memproses Restore...' : 'Restore Data Sekarang' }}
          </button>
        </div>
      </div>
    </div>

    <!-- Restore History -->
    <div v-if="restoreHistories.length > 0" class="bg-white rounded-2xl border border-slate-200 shadow-xs overflow-hidden">
      <div class="p-6 border-b border-slate-100 bg-slate-50/50 flex justify-between items-center">
        <div>
          <h2 class="font-bold text-slate-800 flex items-center gap-2">
            <i class="pi pi-list text-indigo-500"></i> Riwayat Pemulihan Data
          </h2>
          <p class="text-xs text-slate-500 mt-1">Daftar log aktivitas pemulihan (restore) database sistem ke masa lalu.</p>
        </div>
      </div>
      <div class="overflow-x-auto">
        <table class="w-full text-left border-collapse">
          <thead>
            <tr class="bg-slate-50 border-b border-slate-200 text-xs text-slate-500 uppercase tracking-wider">
              <th class="p-4 font-bold">Waktu Restore</th>
              <th class="p-4 font-bold">Nama File Backup</th>
              <th class="p-4 font-bold">Dieksekusi Oleh</th>
              <th class="p-4 font-bold text-center">Status</th>
            </tr>
          </thead>
          <tbody class="text-sm">
            <tr v-for="(history, i) in restoreHistories" :key="history.id || i" class="border-b border-slate-100 hover:bg-slate-50/50 transition-colors">
              <td class="p-4 text-slate-700 font-medium whitespace-nowrap">
                {{ new Date(history.restored_at).toLocaleString('id-ID', {day: 'numeric', month: 'long', year: 'numeric', hour: '2-digit', minute: '2-digit'}) }}
              </td>
              <td class="p-4">
                <div class="inline-flex items-center gap-2 px-2 py-1 bg-indigo-50 text-indigo-700 font-mono text-xs rounded border border-indigo-100 break-all">
                  <i class="pi pi-file-zip"></i> {{ history.file_name }}
                </div>
              </td>
              <td class="p-4">
                <div class="text-slate-800 font-bold">{{ history.restored_by_name }}</div>
                <div class="text-[10px] font-mono text-slate-500">{{ history.restored_by_email }}</div>
              </td>
              <td class="p-4 text-center">
                <span 
                  class="inline-flex items-center px-2.5 py-0.5 rounded-full text-[10px] font-bold uppercase tracking-wider shadow-2xs"
                  :class="[
                    history.status === 'success' ? 'bg-emerald-50 text-emerald-600 border border-emerald-100' : 'bg-rose-50 text-rose-600 border border-rose-100'
                  ]"
                >
                  {{ history.status === 'success' ? 'BERHASIL' : (history.status === 'rejected' ? 'DITOLAK' : 'GAGAL') }}
                </span>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>

    <!-- Disconnect Confirmation Modal -->
    <div v-if="showDisconnectModal" class="fixed inset-0 z-50 flex items-center justify-center p-4">
      <div class="fixed inset-0 bg-slate-900/60 backdrop-blur-xs" @click="showDisconnectModal = false"></div>
      <div class="relative bg-white rounded-2xl shadow-2xl w-full max-w-md overflow-hidden p-6 border border-slate-200">
        <div class="w-12 h-12 rounded-full bg-rose-100 text-rose-600 flex items-center justify-center mb-4">
          <i class="pi pi-power-off text-xl"></i>
        </div>
        <h3 class="text-lg font-bold text-slate-900 mb-2">Putuskan Akun Google Drive?</h3>
        <p class="text-sm text-slate-600 mb-2 leading-relaxed">
          Anda akan memutuskan akun:
        </p>
        <div class="p-3 bg-slate-50 border border-slate-200 rounded-xl mb-4">
          <p class="font-bold text-sm text-slate-800">{{ accountToDisconnect?.name || 'Akun Google' }}</p>
          <p class="font-mono text-xs text-indigo-600">{{ accountToDisconnect?.email }}</p>
        </div>
        <p class="text-xs text-slate-500 mb-6 leading-relaxed">
          Akun ini tidak akan lagi menerima file cadangan otomatis. File cadangan yang sudah tersimpan di Drive akun ini tidak akan dihapus.
        </p>
        <div class="flex justify-end gap-3">
          <button 
            @click="showDisconnectModal = false" 
            class="px-4 py-2 bg-slate-100 hover:bg-slate-200 text-slate-700 text-sm font-semibold rounded-xl transition-colors cursor-pointer"
          >
            Batal
          </button>
          <button 
            @click="handleDisconnect" 
            :disabled="isDisconnecting"
            class="px-4 py-2 bg-rose-600 hover:bg-rose-700 text-white text-sm font-semibold rounded-xl transition-colors shadow-xs disabled:opacity-50 cursor-pointer"
          >
            {{ isDisconnecting ? 'Memutuskan...' : 'Ya, Putuskan Akun Ini' }}
          </button>
        </div>
      </div>
    </div>

    <!-- Restore Confirmation Modal -->
    <div v-if="showRestoreModal" class="fixed inset-0 z-50 flex items-center justify-center p-4 sm:p-0">
      <div class="fixed inset-0 bg-slate-900/60 backdrop-blur-xs transition-opacity" @click="showRestoreModal = false"></div>
      <div class="relative bg-white rounded-2xl shadow-2xl w-full max-w-md overflow-hidden transform transition-all border border-rose-100">
        <div class="bg-rose-50 p-6 flex items-center justify-center border-b border-rose-100">
          <div class="w-16 h-16 bg-rose-100 rounded-full flex items-center justify-center text-rose-600 shadow-inner">
            <i class="pi pi-exclamation-triangle text-3xl"></i>
          </div>
        </div>
        <div class="p-6">
          <h3 class="text-xl font-black text-center text-slate-900 mb-2">Konfirmasi Restore Database</h3>
          <p class="text-sm text-slate-600 text-center mb-6 leading-relaxed">
            Seluruh data transaksi, pengguna, dan konfigurasi saat ini akan <strong>dihapus permanen</strong> dan digantikan oleh data dari file backup.
          </p>
          <div class="bg-amber-50 p-4 rounded-xl border border-amber-200 mb-6">
            <p class="text-xs text-amber-900 font-medium mb-2">Ketik kata <strong>RESTORE</strong> di bawah ini untuk mengonfirmasi:</p>
            <input 
              v-model="confirmText" 
              type="text" 
              placeholder="Ketik RESTORE" 
              class="w-full px-3 py-2 bg-white border border-amber-300 rounded-lg text-sm text-center font-bold tracking-widest uppercase outline-none focus:ring-2 focus:ring-amber-500" 
            />
          </div>
          <div class="flex gap-3">
            <button @click="showRestoreModal = false" class="w-1/2 py-2.5 px-4 bg-slate-100 hover:bg-slate-200 text-slate-700 font-bold rounded-xl text-sm transition-colors cursor-pointer">
              Batal
            </button>
            <button 
              @click="handleRestore" 
              :disabled="confirmText !== 'RESTORE' || isRestoring" 
              class="w-1/2 py-2.5 px-4 bg-rose-600 hover:bg-rose-700 text-white font-bold rounded-xl text-sm transition-colors shadow-lg shadow-rose-200 disabled:opacity-50 disabled:cursor-not-allowed cursor-pointer"
            >
              Lanjutkan Restore
            </button>
          </div>
        </div>
      </div>
    </div>

  </div>
</template>

<script setup>
import { ref, onMounted } from 'vue'
import { useGdriveBackup } from '../../composables/useGdriveBackup'

const {
  isConfigured,
  isConnected,
  accounts,
  backupStatuses,
  restoreHistories,
  systemInfo,
  folderInputs,
  avatarErrors,
  scheduleForm,
  isConnecting,
  isDisconnecting,
  isSavingFolder,
  isSavingSchedule,
  isBackingUp,
  isRestoring,
  toast,
  notify,
  getDiskEmail,
  getDriveUrl,
  getDiskDriveUrl,
  fetchStatus,
  fetchRestoreHistory,
  connectGoogleDrive,
  disconnectGoogleDrive,
  saveFolderId,
  saveSchedule,
  runManualBackup,
  runRestore,
  handleOAuthParams
} = useGdriveBackup()

// Local modal states
const showDisconnectModal = ref(false)
const accountToDisconnect = ref(null)
const showRestoreModal = ref(false)
const confirmText = ref('')
const selectedFile = ref(null)

const confirmDisconnect = (acc) => {
  accountToDisconnect.value = acc
  showDisconnectModal.value = true
}

const handleDisconnect = async () => {
  const ok = await disconnectGoogleDrive(accountToDisconnect.value)
  if (ok) {
    showDisconnectModal.value = false
    accountToDisconnect.value = null
  }
}

const handleFileSelect = (event) => {
  const file = event.target.files[0]
  if (file) {
    if (!file.name.toLowerCase().endsWith('.zip')) {
      notify('Hanya file .zip yang diperbolehkan', 'error')
      event.target.value = ''
      return
    }
    selectedFile.value = file
  }
}

const confirmRestore = () => {
  confirmText.value = ''
  showRestoreModal.value = true
}

const handleRestore = async () => {
  if (!selectedFile.value || confirmText.value !== 'RESTORE') return
  const result = await runRestore(selectedFile.value)
  showRestoreModal.value = false
  if (result.success) {
    selectedFile.value = null
    const dropzone = document.getElementById('dropzone-file')
    if (dropzone) dropzone.value = ''
  }
}

onMounted(async () => {
  handleOAuthParams()
  await fetchStatus()
  await fetchRestoreHistory()
})
</script>
