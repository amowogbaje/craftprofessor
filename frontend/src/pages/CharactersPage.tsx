import { Navigate, useParams, useSearchParams } from 'react-router-dom'
import { useQuery } from '@tanstack/react-query'
import { Card, CardContent } from '@/components/ui/card'
import { Badge } from '@/components/ui/badge'
import { fetchCharacterDetail, fetchSeriesCharacters, fetchStoryCharacters } from '@/lib/api-content'
import type { Character, CharactersResponse } from '@/lib/types'

function CharacterCard({ character, selected, onClick }: { character: Character; selected: boolean; onClick: () => void }) {
  return (
    <button
      onClick={onClick}
      className={`flex flex-col items-center gap-1.5 rounded-md border p-2 transition ${
        selected ? 'border-primary bg-primary/5' : 'border-transparent hover:border-border'
      }`}
    >
      <div className="h-20 w-20 overflow-hidden rounded-full border border-border bg-secondary">
        {character.img_url ? (
          <img src={character.img_url} alt={character.name} className="h-full w-full object-cover" />
        ) : (
          <div className="flex h-full w-full items-center justify-center text-xs text-muted-foreground">?</div>
        )}
      </div>
      <span className="max-w-[6rem] truncate text-xs">{character.name}</span>
    </button>
  )
}

function CharacterDetail({ characterId }: { characterId: number }) {
  const query = useQuery({
    queryKey: ['characters', characterId],
    queryFn: () => fetchCharacterDetail(characterId),
  })

  if (query.isLoading || !query.data) {
    return <p className="text-sm text-muted-foreground">Loading…</p>
  }

  const { data: character, scenes } = query.data

  return (
    <div>
      <div className="mb-3 flex items-center gap-2">
        <h2 className="text-sm font-medium text-muted-foreground">
          Scenes &amp; videos featuring {character.name} ({scenes.length})
        </h2>
        {character.voice && (
          <Badge variant="secondary" className="shrink-0 text-[10px]" title="Dialogue voice assigned to this character">
            Voice: {character.voice}
          </Badge>
        )}
      </div>
      {scenes.length > 0 ? (
        <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
          {scenes.map((scene) => (
            <Card key={scene.id}>
              <CardContent className="flex flex-col gap-2 p-3">
                <div className="overflow-hidden rounded-md border border-border bg-secondary">
                  {scene.video?.video_url ? (
                    <video src={scene.video.video_url} controls className="aspect-[9/16] w-full object-cover" />
                  ) : scene.image_generated_url ? (
                    <img src={scene.image_generated_url} alt={scene.prompt} className="aspect-[9/16] w-full object-cover" />
                  ) : (
                    <div className="flex aspect-[9/16] w-full items-center justify-center text-xs text-muted-foreground">
                      Generating…
                    </div>
                  )}
                </div>
                <div className="flex items-center justify-between">
                  {scene.story && <span className="truncate text-[10px] text-muted-foreground">{scene.story.title || 'Story'}</span>}
                  <Badge variant={scene.video ? 'success' : 'secondary'} className="shrink-0 text-[10px]">
                    {scene.video ? 'VIDEO' : 'IMAGE'}
                  </Badge>
                </div>
                {scene.dialogue_lines && scene.dialogue_lines.length > 0 ? (
                  <div className="space-y-0.5 text-xs text-muted-foreground">
                    {scene.dialogue_lines.map((line, i) => (
                      <p key={i} className="line-clamp-2">
                        <span className="font-medium text-foreground">{line.character_name}:</span> {line.text}
                      </p>
                    ))}
                  </div>
                ) : (
                  scene.narration && <p className="line-clamp-2 text-xs text-muted-foreground">{scene.narration}</p>
                )}
              </CardContent>
            </Card>
          ))}
        </div>
      ) : (
        <p className="text-sm text-muted-foreground">No scenes generated for {character.name} yet.</p>
      )}
    </div>
  )
}

function CharactersPageBody({ queryKey, queryFn }: { queryKey: unknown[]; queryFn: () => Promise<CharactersResponse> }) {
  const [searchParams, setSearchParams] = useSearchParams()
  const selectedId = searchParams.get('character') ? Number(searchParams.get('character')) : null

  const query = useQuery({ queryKey, queryFn })

  if (query.isLoading || !query.data) {
    return <p className="text-sm text-muted-foreground">Loading…</p>
  }

  // A standalone story that turned out to belong to a series — redirect to
  // the canonical series-scoped URL (see CharacterController::forStory).
  if (query.data.redirect_to) {
    return <Navigate to={query.data.redirect_to} replace />
  }

  const characters: Character[] = query.data.data

  return (
    <div className="flex flex-col gap-8">
      <div>
        <h1 className="font-display text-2xl font-medium">{query.data.title} — Characters</h1>
        <p className="mt-1 text-sm text-muted-foreground">{characters.length} character{characters.length === 1 ? '' : 's'}</p>
      </div>

      {characters.length > 0 ? (
        <div className="flex flex-wrap gap-3">
          {characters.map((character) => (
            <CharacterCard
              key={character.id}
              character={character}
              selected={selectedId === character.id}
              onClick={() => setSearchParams(selectedId === character.id ? {} : { character: String(character.id) })}
            />
          ))}
        </div>
      ) : (
        <p className="text-sm text-muted-foreground">No characters generated yet.</p>
      )}

      {selectedId && <CharacterDetail characterId={selectedId} />}
    </div>
  )
}

export function StoryCharactersPage() {
  const { slug } = useParams<{ slug: string }>()
  return (
    <CharactersPageBody
      queryKey={['stories', slug, 'characters']}
      queryFn={() => fetchStoryCharacters(slug!)}
    />
  )
}

export function SeriesCharactersPage() {
  const { slug } = useParams<{ slug: string }>()
  return (
    <CharactersPageBody
      queryKey={['series', slug, 'characters']}
      queryFn={() => fetchSeriesCharacters(slug!)}
    />
  )
}
