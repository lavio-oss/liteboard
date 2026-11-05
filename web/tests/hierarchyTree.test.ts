import assert from 'node:assert/strict'
import test from 'node:test'
import { HIERARCHY_CARD_WIDTH, layoutHierarchy, withAncestors } from '../app/utils/hierarchyTree.ts'

const entries = [
  { id: 't', parentId: null },
  { id: 'p', parentId: 't' },
  { id: 'a1', parentId: 'p' },
  { id: 'a2', parentId: 'p' },
  { id: 'a1x', parentId: 'a1' }
]

test('a selected deep node pulls in its whole ancestor path', () => {
  assert.deepEqual([...withAncestors(['a1x'], entries)].sort(), ['a1', 'a1x', 'p', 't'])
})

test('unknown ids are ignored and nothing selected shows nothing', () => {
  assert.equal(withAncestors(['ghost'], entries).size, 0)
  assert.equal(withAncestors([], entries).size, 0)
})

test('layout keeps one connected root, increasing rows and no overlapping siblings', () => {
  const visible = withAncestors(['a1x', 'a2'], entries)
  const { positions, childrenOf } = layoutHierarchy(entries, visible)

  assert.deepEqual(childrenOf.get('p'), ['a1', 'a2'])
  assert.ok(positions.get('p')!.y > positions.get('t')!.y)
  assert.ok(positions.get('a1x')!.y > positions.get('a1')!.y)
  assert.ok(positions.get('a2')!.x - positions.get('a1')!.x >= HIERARCHY_CARD_WIDTH)
})
