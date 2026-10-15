import axios from 'axios'

// Gunakan window.axios (bawaan Laravel/Inertia) jika ada, atau buat instance axios default
let currentApi = (typeof window !== 'undefined' && window.axios)
  ? window.axios
  : axios.create({
      baseURL: (typeof import.meta !== 'undefined' && import.meta.env?.VITE_API_URL) ? import.meta.env.VITE_API_URL : '',
      headers: {
        'Accept': 'application/json',
        'X-Requested-With': 'XMLHttpRequest'
      },
      withCredentials: true
    })

if (currentApi && currentApi.defaults) {
  currentApi.defaults.withCredentials = true
}

// Otomatis inject Bearer token jika tersimpan di localStorage/sessionStorage (untuk SPA Sanctum)
if (currentApi && currentApi.interceptors) {
  currentApi.interceptors.request.use((config) => {
    if (typeof window !== 'undefined') {
      const token = window.localStorage?.getItem('token')
        || window.localStorage?.getItem('auth_token')
        || window.localStorage?.getItem('access_token')
        || (window.sessionStorage ? window.sessionStorage.getItem('token') : null)

      if (token && !config.headers.Authorization) {
        config.headers.Authorization = `Bearer ${token}`
      }
    }
    return config
  })
}

let customPrefix = null

/**
 * Konfigurasi custom axios instance atau prefix endpoint jika berbeda
 */
export function configureGdriveBackupApi({ apiInstance, prefix } = {}) {
  if (apiInstance) {
    currentApi = apiInstance
    if (currentApi.defaults) {
      currentApi.defaults.withCredentials = true
    }
  }
  if (prefix !== undefined) customPrefix = prefix.replace(/\/$/, '')
}

function resolvePath(endpoint) {
  const cleanEndpoint = endpoint.replace(/^\/+/, '')
  if (customPrefix !== null) {
    const cleanPrefix = customPrefix.replace(/^\/+/, '').replace(/\/+$/, '')
    return `${cleanPrefix}/${cleanEndpoint}`
  }

  const rawBase = (currentApi.defaults?.baseURL || '').toString().trim()
  const base = rawBase.toLowerCase().replace(/\/+$/, '')

  // Jika baseURL sudah mencakup prefix modul secara lengkap (e.g. .../api/v1/backup)
  if (base.endsWith('/api/v1/backup') || base.endsWith('/v1/backup')) {
    return cleanEndpoint
  }

  // Jika baseURL sudah mencakup versi api (e.g. .../api/v1 atau .../v1)
  if (base.endsWith('/api/v1') || base.endsWith('/v1')) {
    return `backup/${cleanEndpoint}`
  }

  // Jika baseURL berakhiran /api (e.g. http://localhost:8000/api)
  if (base.endsWith('/api')) {
    return `v1/backup/${cleanEndpoint}`
  }

  // Jika ada baseURL tanpa suffix /api (e.g. http://localhost:8000)
  if (rawBase) {
    return `api/v1/backup/${cleanEndpoint}`
  }

  // Jika tidak ada baseURL sama sekali (Laravel Inertia / Monorepo relative)
  return `/api/v1/backup/${cleanEndpoint}`
}

export const gdriveBackupApi = {
  getStatus() {
    return currentApi.get(resolvePath('status'))
  },

  getConnectUrl() {
    return currentApi.get(resolvePath('google/connect'))
  },

  disconnectAccount(id) {
    return currentApi.post(resolvePath('google/disconnect'), { id })
  },

  updateFolderId(id, folder_id) {
    return currentApi.post(resolvePath('google/folder'), { id, folder_id })
  },

  updateSchedule(schedule) {
    return currentApi.post(resolvePath('schedule'), { schedule })
  },

  runBackup() {
    return currentApi.post(resolvePath('run'))
  },

  restoreDatabase(file) {
    const formData = new FormData()
    formData.append('backup_file', file)
    return currentApi.post(resolvePath('restore'), formData, {
      headers: {
        'Content-Type': 'multipart/form-data'
      }
    })
  },

  getRestoreHistory() {
    return currentApi.get(resolvePath('restore-history'))
  }
}

export default gdriveBackupApi
