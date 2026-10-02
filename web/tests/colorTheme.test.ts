import assert from 'node:assert/strict'
import { test } from 'node:test'
import { colorPreferenceSchema, neutralColors, primaryColors } from '../app/utils/colorTheme.ts'

test('color preference schema accepts every available palette', () => {
  for (const primary of primaryColors) {
    for (const neutral of neutralColors) {
      assert.equal(colorPreferenceSchema.safeParse({ primary, neutral }).success, true)
    }
  }
})

test('color preference schema rejects unsupported colors', () => {
  assert.equal(colorPreferenceSchema.safeParse({ primary: 'brand', neutral: 'zinc' }).success, false)
  assert.equal(colorPreferenceSchema.safeParse({ primary: 'indigo', neutral: 'brand' }).success, false)
})
