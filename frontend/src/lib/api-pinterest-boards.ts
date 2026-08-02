import { api } from './http'

export interface PinterestBoard {
  id: number
  external_board_id: string
  name: string
  description: string | null
  topics: string[] | null
  source: 'synced' | 'ai_created'
  is_active: boolean
}

export interface PinterestBoardSettings {
  board_posting_mode: 'dynamic' | 'fixed'
  boards: PinterestBoard[]
  preferred_board_ids: number[]
}

export async function fetchPinterestBoardSettings() {
  const { data } = await api.get<PinterestBoardSettings>('/pinterest/boards')
  return data
}

export async function syncPinterestBoards() {
  const { data } = await api.post<{ message: string; boards: PinterestBoard[] }>('/pinterest/boards/sync')
  return data
}

export async function updatePinterestBoard(boardId: number, updates: Partial<Pick<PinterestBoard, 'is_active' | 'description' | 'topics'>>) {
  const { data } = await api.patch<{ board: PinterestBoard }>(`/pinterest/boards/${boardId}`, updates)
  return data
}

export async function updatePostingMode(mode: 'dynamic' | 'fixed', boardIds?: number[]) {
  const { data } = await api.put<{ message: string; board_posting_mode: string; preferred_board_ids: number[] }>(
    '/pinterest/posting-mode',
    { board_posting_mode: mode, board_ids: boardIds }
  )
  return data
}
