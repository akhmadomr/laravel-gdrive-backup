import { ref, reactive } from 'vue'
import gdriveBackupApi from '../services/gdriveBackupApi'

export function useGdriveBackup() {
  // Main states
  const isConfigured = ref(true)
  const isConnected = ref(false)
  const accounts = ref([])
  const backupStatuses = ref([])
  const restoreHistories = ref([])
  const systemInfo = ref({ app_name: '', is_ready: true, system_id_masked: null })
  const folderInputs = ref({})
  const avatarErrors = ref({})

  // Loading states
  const isLoading = ref(false)
  const isConnecting = ref(false)
  const isDisconnecting = ref(false)
  const isSavingFolder = ref({})
  const isSavingSchedule = ref(false)
  const isBackingUp = ref(false)
  const isRestoring = ref(false)

  // Schedule form state
  const scheduleForm = ref({
    frequency: 'daily',
    day: 1,
    date: 1,
    time: '01:30'
  })

  // Built-in Toast notification state (can be used standalone or bridged to host toast)
  const toast = reactive({
    show: false,
    message: '',
    type: 'success', // success | error | warning
    timeout: null
  })

  const notify = (message, type = 'success') => {
    if (toast.timeout) clearTimeout(toast.timeout)
    toast.message = message
    toast.type = type
    toast.show = true
    toast.timeout = setTimeout(() => {
      toast.show = false
    }, 4000)
  }

  // Helpers
  const getDiskEmail = (disk) => {
    if (disk === 'google') {
      return accounts.value[0]?.email || ''
    }
    const match = disk.match(/^google:(\S+)$/)
    if (match) {
      const found = accounts.value.find(a => a.id === match[1])
      return found?.email || ''
    }
    const matchOld = disk.match(/^google_(\d+)$/)
    if (matchOld) {
      const idx = parseInt(matchOld[1], 10) - 1
      return accounts.value[idx]?.email || ''
    }
    return ''
  }

  const getDriveUrl = (acc) => {
    if (acc?.folder_id) {
      return `https://drive.google.com/drive/folders/${acc.folder_id}`
    }
    return 'https://drive.google.com/drive/my-drive'
  }

  const getDiskDriveUrl = (disk) => {
    if (disk === 'google') return getDriveUrl(accounts.value[0])
    const match = disk.match(/^google:(\S+)$/)
    if (match) {
      const found = accounts.value.find(a => a.id === match[1])
      return getDriveUrl(found)
    }
    const matchOld = disk.match(/^google_(\d+)$/)
    if (matchOld) {
      const idx = parseInt(matchOld[1], 10) - 1
      return getDriveUrl(accounts.value[idx])
    }
    return 'https://drive.google.com/drive/my-drive'
  }

  // API Actions
  const fetchStatus = async () => {
    isLoading.value = true
    try {
      const { data } = await gdriveBackupApi.getStatus()
      if (data.success && data.data) {
        isConfigured.value = data.data.is_configured
        isConnected.value = data.data.is_connected
        accounts.value = data.data.accounts || []

        const fInputs = {}
        accounts.value.forEach(acc => {
          fInputs[acc.id] = acc.folder_id || ''
        })
        folderInputs.value = fInputs

        backupStatuses.value = data.data.status || []
        if (data.data.schedule) {
          scheduleForm.value = { ...scheduleForm.value, ...data.data.schedule }
        }
        if (data.data.system) {
          systemInfo.value = data.data.system
        }
      }
    } catch (error) {
      notify('Gagal memuat status backup', 'error')
    } finally {
      isLoading.value = false
    }
  }

  const fetchRestoreHistory = async () => {
    try {
      const { data } = await gdriveBackupApi.getRestoreHistory()
      restoreHistories.value = data.data || []
    } catch (error) {
      console.error('Gagal memuat riwayat restore', error)
    }
  }

  const connectGoogleDrive = async () => {
    isConnecting.value = true
    try {
      const { data } = await gdriveBackupApi.getConnectUrl()
      if (data.success && data.auth_url) {
        window.location.href = data.auth_url
      } else {
        notify(data.message || 'Gagal memulai koneksi Google Drive', 'error')
      }
    } catch (error) {
      notify(error.response?.data?.message || 'Gagal menghubungi server untuk koneksi Google Drive', 'error')
    } finally {
      isConnecting.value = false
    }
  }

  const disconnectGoogleDrive = async (account) => {
    if (!account) return false
    isDisconnecting.value = true
    try {
      const { data } = await gdriveBackupApi.disconnectAccount(account.id)
      notify(data.message || 'Koneksi Google Drive berhasil diputuskan.', 'success')
      await fetchStatus()
      return true
    } catch (error) {
      notify(error.response?.data?.message || 'Gagal memutuskan koneksi Google Drive', 'error')
      return false
    } finally {
      isDisconnecting.value = false
    }
  }

  const saveFolderId = async (account) => {
    if (!account) return
    isSavingFolder.value[account.id] = true
    try {
      const { data } = await gdriveBackupApi.updateFolderId(account.id, folderInputs.value[account.id])
      notify(data.message || 'Folder ID berhasil disimpan!', 'success')
      await fetchStatus()
    } catch (error) {
      notify(error.response?.data?.message || 'Gagal menyimpan Folder ID', 'error')
    } finally {
      isSavingFolder.value[account.id] = false
    }
  }

  const saveSchedule = async () => {
    isSavingSchedule.value = true
    try {
      const { data } = await gdriveBackupApi.updateSchedule(scheduleForm.value)
      notify(data.message || 'Jadwal backup otomatis berhasil disimpan!', 'success')
      await fetchStatus()
    } catch (error) {
      notify(error.response?.data?.message || 'Gagal menyimpan jadwal backup', 'error')
    } finally {
      isSavingSchedule.value = false
    }
  }

  const runManualBackup = async () => {
    isBackingUp.value = true
    try {
      const { data } = await gdriveBackupApi.runBackup()
      notify(data.message || 'Backup sedang diproses di latar belakang.', 'success')
      await fetchStatus()
    } catch (error) {
      notify(error.response?.data?.message || 'Gagal menjalankan backup', 'error')
    } finally {
      isBackingUp.value = false
    }
  }

  const runRestore = async (file) => {
    if (!file) return { success: false }
    isRestoring.value = true
    try {
      const { data } = await gdriveBackupApi.restoreDatabase(file)
      notify(data.message || 'Restore database berhasil!', 'success')
      await fetchStatus()
      await fetchRestoreHistory()
      return { success: true, message: data.message }
    } catch (error) {
      let msg = error.response?.data?.message || 'Gagal melakukan restore database'
      if (msg.includes('failed to upload') || error.response?.status === 413) {
        msg = 'Gagal: Ukuran file melebihi batas upload server. Silakan periksa konfigurasi upload PHP di server.'
      }
      notify(msg, 'error')
      return { success: false, message: msg }
    } finally {
      isRestoring.value = false
    }
  }

  const handleOAuthParams = (route = null, router = null) => {
    if (typeof window === 'undefined') return

    const url = new URL(window.location.href)
    const googleAuth = route?.query?.google_auth || url.searchParams.get('google_auth')
    const message = route?.query?.message || url.searchParams.get('message')

    if (googleAuth === 'success') {
      notify('Selamat! Akun Google Drive Anda berhasil terhubung.', 'success')
      cleanUrl()
    } else if (googleAuth === 'error') {
      notify('Koneksi Google Drive gagal: ' + (message || 'Otorisasi ditolak.'), 'error')
      cleanUrl()
    }

    function cleanUrl() {
      if (router?.replace) {
        router.replace({ query: {} })
      } else {
        url.searchParams.delete('google_auth')
        url.searchParams.delete('message')
        window.history.replaceState({}, '', url.pathname + (url.search ? url.search : ''))
      }
    }
  }

  return {
    // State
    isConfigured,
    isConnected,
    accounts,
    backupStatuses,
    restoreHistories,
    systemInfo,
    folderInputs,
    avatarErrors,
    scheduleForm,
    isLoading,
    isConnecting,
    isDisconnecting,
    isSavingFolder,
    isSavingSchedule,
    isBackingUp,
    isRestoring,
    toast,

    // Methods
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
  }
}
