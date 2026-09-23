import type { AvatarProps } from '@nuxt/ui'

export interface Member {
  name: string
  username: string
  role: 'member' | 'owner'
  avatar: AvatarProps
}
