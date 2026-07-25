import { api } from './http'
import type { CoinTransaction, Paginated, PublishSetting, WalletDetail } from './types'

export async function fetchWallet() {
  const { data } = await api.get<WalletDetail>('/wallet')
  return data
}

export async function fetchTransactions() {
  const { data } = await api.get<Paginated<CoinTransaction>>('/wallet/transactions')
  return data
}

export async function initiateTopup(packageIndex: number) {
  const { data } = await api.post<{ tx_ref: string; payment_link: string }>('/wallet/topup', {
    package_index: packageIndex,
  })
  return data
}

export async function fetchPublishSettings() {
  const { data } = await api.get<PublishSetting>('/publish-settings')
  return data
}

export async function updatePublishSettings(payload: Partial<PublishSetting>) {
  const { data } = await api.put<PublishSetting>('/publish-settings', payload)
  return data
}
