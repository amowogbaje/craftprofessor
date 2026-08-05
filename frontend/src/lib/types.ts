export interface Wallet {
  balance: number
  lifetime_purchased: number
  lifetime_spent: number
}

export interface PublishSetting {
  id: number
  daily_image_limit: number
  daily_video_limit: number
  monthly_image_limit: number | null
  monthly_video_limit: number | null
  timezone: string
  auto_publish: boolean
}

export interface User {
  id: number
  name: string
  email: string
  phone: string | null
  avatar_url: string | null
  role: 'user' | 'admin'
  email_verified_at: string | null
  phone_verified_at: string | null
  wallet?: Wallet
  publish_setting?: PublishSetting
}

export type OtpChannel = 'email' | 'sms'
export type OtpPurpose = 'signup' | 'password_reset'

export type ContentStatus = 'draft' | 'scheduled' | 'published'

export interface FeedItem {
  type: 'image' | 'video'
  id: number
  url: string
  prompt?: string
  status: ContentStatus
  scheduled_at: string | null
  published_at: string | null
  story_id?: number
  has_video?: boolean
  source_image_prompt_id?: number
  pinterest_pin_id?: string | null
  sort_at: string
}

export interface Story {
  id: number
  user_id: number
  series_id: number | null
  episode_number: number | null
  title?: string | null
  story_link: string | null
  story_text: string | null
  user_supplied_text: string | null
  prompt_generated: boolean
  image_prompts_count?: number
  series?: { id: number; title: string; slug: string } | null
  created_at: string
}

export interface StorySeries {
  id: number
  user_id: number
  title: string
  slug: string
  description: string | null
  source?: string | null
  source_slug?: string | null
  cover_image_url?: string | null
  external_url?: string | null
  stories_count?: number
  created_at: string
}

export interface StoryVerseImportResult {
  message: string
  series: {
    id: number
    title: string
    slug: string
    source_slug: string | null
    cover_image_url: string | null
    episodes: Array<{
      id: number
      episode_number: number | null
      title: string | null
      story_link: string | null
      story_text_fetched: boolean
    }>
  }
}

export interface LinkStatsBySocial {
  social_media: string
  clicks: number
}

export interface LinkStatsByDay {
  date: string
  social_media: string
  clicks: number
}

export interface LinkStatsTopStory {
  story_id: number
  clicks: number
  story?: { id: number; title: string | null; story_link: string | null; episode_number: number | null; series_id: number | null }
}

export interface PostStatsByPlatform {
  platform: string
  posted: number
  failed: number
}

export interface PostStatsByDay {
  date: string
  platform: string
  posted: number
}

export interface PeriodSummary {
  clicks: number
  clicks_by_platform: { social_media: string; clicks: number }[]
  posts: number
  posts_by_platform: { platform: string; posts: number }[]
}

export interface LinkStats {
  range: { from: string; to: string }
  clicks: {
    total: number
    by_social_media: LinkStatsBySocial[]
    by_day: LinkStatsByDay[]
    top_stories: LinkStatsTopStory[]
  }
  posts: {
    total_posted: number
    total_failed: number
    by_platform: PostStatsByPlatform[]
    by_day: PostStatsByDay[]
  }
  periods: {
    today: PeriodSummary
    this_week: PeriodSummary
    this_month: PeriodSummary
  }
}

export interface CoinPackage {
  coins: number
  amount: number
  currency: string
  label: string
}

export interface CoinCosts {
  image_prompt: number
  image_generation: number
  video_prompt: number
  video_generation: number
  character_portrait: number
}

export interface WalletDetail {
  balance: number
  lifetime_purchased: number
  lifetime_spent: number
  packages: CoinPackage[]
  costs: CoinCosts
}

export interface CoinTransaction {
  id: number
  type: 'credit' | 'debit'
  reason: string
  amount: number
  balance_after: number
  created_at: string
}

export interface Paginated<T> {
  data: T[]
  current_page: number
  last_page: number
  per_page: number
  total: number
}

export interface ApiErrorShape {
  message?: string
  errors?: Record<string, string[]>
  required_coins?: number
  available_coins?: number
}

export type SocialProviderKey = 'pinterest' | 'linkedin' | 'twitter' | 'youtube' | 'instagram' | 'facebook'

export type CauseStatus = 'pending_payment' | 'active' | 'paused' | 'archived'
export type CausePaymentStatus = 'pending' | 'paid' | 'waived'
export type CauseMemberStatus = 'invited' | 'joined' | 'declined' | 'opted_out'
export type CauseBroadcastStatus = 'scheduled' | 'posted' | 'failed' | 'skipped'
export type CauseMediaType = 'image' | 'video'

export interface Cause {
  id: number
  owner_id: number
  title: string
  slug: string
  description: string | null
  goal: string | null
  status: CauseStatus
  creation_fee_amount: string
  creation_fee_currency: string
  payment_status: CausePaymentStatus
  created_at: string
  owner?: Pick<User, 'id' | 'name' | 'avatar_url'>
  media?: CauseMedia[]
  joined_members?: CauseMember[]
  joined_members_count?: number
  viewer_membership?: CauseMember | null
}

export interface CauseMedia {
  id: number
  cause_id: number
  uploaded_by: number
  title: string
  details: string | null
  url: string
  type: CauseMediaType
  link_url: string | null
  created_at: string
}

export interface CauseMember {
  id: number
  cause_id: number
  user_id: number
  invited_by: number | null
  status: CauseMemberStatus
  joined_at: string | null
  opted_out_at: string | null
  user?: Pick<User, 'id' | 'name' | 'email' | 'avatar_url'>
}

export interface CauseBroadcast {
  id: number
  cause_id: number
  cause_media_id: number
  user_id: number
  provider: SocialProviderKey
  scheduled_by: number
  scheduled_at: string
  timezone: string
  status: CauseBroadcastStatus
  error: string | null
  posted_at: string | null
  media?: CauseMedia
  cause?: Pick<Cause, 'id' | 'title' | 'slug'>
  user?: Pick<User, 'id' | 'name' | 'avatar_url'>
}

