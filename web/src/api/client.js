import axios from 'axios'

export const http = axios.create({
  baseURL: '/api',
  headers: { Accept: 'application/json' },
})

let tokenGetter = () => null
let unauthorizedHandler = () => {}

export function configureClient({ getToken, onUnauthorized }) {
  tokenGetter = getToken
  unauthorizedHandler = onUnauthorized
  return () => {
    tokenGetter = () => null
    unauthorizedHandler = () => {}
  }
}

http.interceptors.request.use((config) => {
  const token = tokenGetter()
  if (token) {
    config.headers.Authorization = `Bearer ${token}`
  }
  return config
})

http.interceptors.response.use(
  (response) => response,
  (error) => {
    if (error.response?.status === 401 && tokenGetter()) {
      unauthorizedHandler()
    }
    return Promise.reject(error)
  },
)

export function errorMessage(error, fallback = 'Something went wrong. Try again.') {
  const data = error.response?.data
  if (data?.message) return data.message
  return fallback
}