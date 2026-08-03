import { useEffect, useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Loader2, RefreshCw, Sparkles, Check } from 'lucide-react'
import { Card, CardContent, CardHeader, CardTitle, CardDescription } from '@/components/ui/card'
import { Button } from '@/components/ui/button'
import { Badge } from '@/components/ui/badge'
import {
  fetchPinterestBoardSettings,
  syncPinterestBoards,
  updatePostingMode,
  PinterestBoard,
} from '@/lib/api-pinterest-boards'
import { apiErrorMessage } from '@/lib/http'
import { useToast } from '@/components/ui/use-toast'
import { cn } from '@/lib/utils'

const MAX_FIXED_BOARDS = 3

export function PinterestBoardsPage() {
  const { toast } = useToast()
  const queryClient = useQueryClient()
  const [selected, setSelected] = useState<number[]>([])
  // Decoupled from data.board_posting_mode on purpose: switching to "Fixed"
  // needs to unlock board picking in the UI *before* anything is saved
  // (there's nothing valid to save yet — no boards are chosen). Only
  // "Save selection" (or picking "Dynamic", which needs no boards) ever
  // actually calls the API.
  const [uiMode, setUiMode] = useState<'dynamic' | 'fixed'>('dynamic')

  const { data, isLoading } = useQuery({ queryKey: ['pinterest-board-settings'], queryFn: fetchPinterestBoardSettings })

  useEffect(() => {
    if (data) {
      setSelected(data.preferred_board_ids)
      setUiMode(data.board_posting_mode)
    }
  }, [data])

  const syncMutation = useMutation({
    mutationFn: syncPinterestBoards,
    onSuccess: (result) => {
      queryClient.invalidateQueries({ queryKey: ['pinterest-board-settings'] })
      toast({ title: 'Boards synced', description: result.message })
    },
    onError: (err) => toast({ title: 'Sync failed', description: apiErrorMessage(err), variant: 'destructive' }),
  })

  const modeMutation = useMutation({
    mutationFn: ({ mode, boardIds }: { mode: 'dynamic' | 'fixed'; boardIds?: number[] }) => updatePostingMode(mode, boardIds),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['pinterest-board-settings'] })
      toast({ title: 'Preference saved' })
    },
    onError: (err) => toast({ title: 'Could not save', description: apiErrorMessage(err), variant: 'destructive' }),
  })

  const toggleBoard = (board: PinterestBoard) => {
    setSelected((current) => {
      if (current.includes(board.id)) return current.filter((id) => id !== board.id)
      if (current.length >= MAX_FIXED_BOARDS) {
        toast({ title: `You can pick up to ${MAX_FIXED_BOARDS} boards`, variant: 'destructive' })
        return current
      }
      return [...current, board.id]
    })
  }

  return (
    <div className="flex flex-col gap-6">
      <div>
        <h1 className="font-display text-2xl font-medium">Pinterest boards</h1>
        <p className="text-sm text-muted-foreground">
          Let AI pick the best board for each pin, or always post to a fixed set you choose — up to {MAX_FIXED_BOARDS} boards per pin either way.
        </p>
      </div>

      <Card>
        <CardHeader>
          <CardTitle>Posting mode</CardTitle>
          <CardDescription>This applies to every pin CraftProfessor posts from now on.</CardDescription>
        </CardHeader>
        <CardContent className="flex flex-col gap-3 sm:flex-row">
          <button
            type="button"
            disabled={modeMutation.isPending}
            onClick={() => {
              setUiMode('dynamic')
              modeMutation.mutate({ mode: 'dynamic' })
            }}
            className={cn(
              'flex flex-1 items-start gap-3 rounded-lg border p-4 text-left transition-colors',
              uiMode === 'dynamic' ? 'border-primary bg-primary/5' : 'border-border hover:bg-secondary'
            )}
          >
            <Sparkles className="mt-0.5 h-4 w-4 shrink-0 text-primary" />
            <div>
              <p className="text-sm font-medium">Dynamic (AI-picked)</p>
              <p className="text-xs text-muted-foreground">
                Gemini reads each pin's content and picks the best-fit board(s), creating a new board when nothing fits.
              </p>
            </div>
          </button>

          <button
            type="button"
            disabled={modeMutation.isPending}
            onClick={() => setUiMode('fixed')}
            className={cn(
              'flex flex-1 items-start gap-3 rounded-lg border p-4 text-left transition-colors',
              uiMode === 'fixed' ? 'border-primary bg-primary/5' : 'border-border hover:bg-secondary'
            )}
          >
            <Check className="mt-0.5 h-4 w-4 shrink-0 text-primary" />
            <div>
              <p className="text-sm font-medium">Fixed boards</p>
              <p className="text-xs text-muted-foreground">
                Pick up to {MAX_FIXED_BOARDS} board(s) below, then save — CraftProfessor will always post to those.
              </p>
            </div>
          </button>
        </CardContent>
      </Card>

      <Card>
        <CardHeader className="flex flex-row items-center justify-between space-y-0">
          <div>
            <CardTitle>Your boards</CardTitle>
            <CardDescription>
              {uiMode === 'fixed'
                ? `Select up to ${MAX_FIXED_BOARDS} — ${selected.length}/${MAX_FIXED_BOARDS} selected.`
                : 'Synced from Pinterest, plus any the AI has created.'}
            </CardDescription>
          </div>
          <Button variant="outline" size="sm" disabled={syncMutation.isPending} onClick={() => syncMutation.mutate()}>
            {syncMutation.isPending ? <Loader2 className="h-4 w-4 animate-spin" /> : <RefreshCw className="h-4 w-4" />}
            Sync from Pinterest
          </Button>
        </CardHeader>
        <CardContent className="flex flex-col gap-2">
          {isLoading ? (
            <p className="text-sm text-muted-foreground">Loading…</p>
          ) : data?.boards.length === 0 ? (
            <p className="text-sm text-muted-foreground">No boards yet — sync from Pinterest, or switch to Dynamic mode and let the AI create one on the next pin.</p>
          ) : (
            data?.boards.map((board) => {
              const isSelected = selected.includes(board.id)
              return (
                <button
                  key={board.id}
                  type="button"
                  disabled={uiMode !== 'fixed'}
                  onClick={() => toggleBoard(board)}
                  className={cn(
                    'flex items-center justify-between gap-3 rounded-md border px-3 py-2.5 text-left transition-colors',
                    uiMode === 'fixed' ? 'cursor-pointer hover:bg-secondary' : 'cursor-default opacity-70',
                    isSelected && uiMode === 'fixed' ? 'border-primary bg-primary/5' : 'border-border'
                  )}
                >
                  <div className="min-w-0 flex-1">
                    <p className="truncate text-sm font-medium">{board.name}</p>
                    {board.description && <p className="truncate text-xs text-muted-foreground">{board.description}</p>}
                  </div>
                  <div className="flex shrink-0 items-center gap-2">
                    {board.source === 'ai_created' && (
                      <Badge variant="secondary" className="text-xs">
                        AI-created
                      </Badge>
                    )}
                    {!board.is_active && (
                      <Badge variant="outline" className="text-xs">
                        Inactive
                      </Badge>
                    )}
                    {uiMode === 'fixed' && isSelected && <Check className="h-4 w-4 text-primary" />}
                  </div>
                </button>
              )
            })
          )}

          {uiMode === 'fixed' && (
            <div className="mt-2 flex items-center gap-3">
              <Button
                size="sm"
                disabled={modeMutation.isPending || selected.length === 0}
                onClick={() => modeMutation.mutate({ mode: 'fixed', boardIds: selected })}
              >
                {modeMutation.isPending ? <Loader2 className="h-4 w-4 animate-spin" /> : 'Save selection'}
              </Button>
              {selected.length === 0 && (
                <p className="text-xs text-muted-foreground">Pick at least 1 board above before saving.</p>
              )}
              {data?.board_posting_mode === 'fixed' && selected.length > 0 && (
                <p className="text-xs text-muted-foreground">
                  Currently posting to {data.preferred_board_ids.length} saved board(s).
                </p>
              )}
            </div>
          )}
        </CardContent>
      </Card>
    </div>
  )
}
