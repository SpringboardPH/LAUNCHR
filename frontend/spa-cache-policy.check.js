import { spawn } from 'node:child_process'
import { existsSync, readdirSync, readFileSync } from 'node:fs'
import http from 'node:http'
import { dirname, join } from 'node:path'
import { fileURLToPath } from 'node:url'
import {
  CACHE_CONTROL,
  classify,
  classifyRequestUrl,
  emitNginxLocations,
  listPublicPathnames,
} from './spa-cache-policy.js'

const root = dirname(fileURLToPath(import.meta.url))
const distDir = join(root, 'dist')
const assetsDir = join(distDir, 'assets')
const nginxPath = join(root, 'spa-cache.nginx.conf')
const publicDir = join(root, 'public')
const previewPort = 4179
const previewOrigin = `http://127.0.0.1:${previewPort}`

function assert(condition, message) {
  if (!condition) {
    throw new Error(message)
  }
}

function classifierConfig() {
  return {
    assetsDir: 'assets',
    publicPathnames: listPublicPathnames(publicDir),
  }
}

function hashedLocationBlock(nginx) {
  const match = nginx.match(/location \^~ \/assets\/ \{[\s\S]*?\n\}/)
  return match ? match[0] : ''
}

function run(command, args) {
  return new Promise((resolve, reject) => {
    const child = spawn(command, args, { cwd: root, stdio: 'inherit' })
    child.on('error', reject)
    child.on('exit', (code) => {
      if (code === 0) {
        resolve()
        return
      }
      reject(new Error(`${command} ${args.join(' ')} exited ${code}`))
    })
  })
}

function distNeedsBuild() {
  if (!existsSync(join(distDir, 'index.html'))) {
    return true
  }
  if (!existsSync(assetsDir)) {
    return true
  }
  return readdirSync(assetsDir).filter((name) => !name.startsWith('.')).length === 0
}

function startPreview() {
  const child = spawn(
    join(root, 'node_modules/.bin/vite'),
    ['preview', '--host', '127.0.0.1', '--port', String(previewPort), '--strictPort'],
    { cwd: root, stdio: ['ignore', 'pipe', 'pipe'] },
  )
  child.stdout.resume()
  return child
}

function killPreview(child) {
  if (!child || child.exitCode !== null) {
    return
  }
  child.kill('SIGKILL')
}

function getPath(pathname) {
  return new Promise((resolve, reject) => {
    const req = http.get(previewOrigin + pathname, { timeout: 3000 }, (res) => {
      res.resume()
      res.on('end', () => {
        resolve({
          statusCode: res.statusCode,
          cacheControl: res.headers['cache-control'],
        })
      })
    })
    req.on('error', reject)
    req.on('timeout', () => {
      req.destroy()
      reject(new Error(`timeout ${pathname}`))
    })
  })
}

async function waitForPreview(child, timeoutMs) {
  const started = Date.now()
  let stderr = ''
  child.stderr.on('data', (chunk) => {
    stderr += chunk.toString()
  })
  while (Date.now() - started < timeoutMs) {
    if (child.exitCode !== null) {
      throw new Error(`vite preview exited ${child.exitCode}: ${stderr}`)
    }
    try {
      const response = await getPath('/')
      if (response.statusCode === 200) {
        return
      }
    } catch {
    }
    await new Promise((resolve) => setTimeout(resolve, 150))
  }
  throw new Error(`vite preview did not become ready on ${previewPort}: ${stderr}`)
}

async function cacheControlFor(pathname) {
  const response = await getPath(pathname)
  return response.cacheControl
}

export async function main() {
  const config = classifierConfig()

  assert(classify('/', config) === 'entry', 'classify / is entry')
  assert(classify('/index.html', config) === 'entry', 'classify /index.html is entry')
  assert(classify('/hr/employees', config) === 'entry', 'classify /hr/employees is entry')
  assert(
    classify('/assets/index-DTpmv5gm.js', config) === 'hashed',
    'classify /assets/index-DTpmv5gm.js is hashed',
  )
  assert(classify('/launchr_logo.svg', config) === 'public', 'classify /launchr_logo.svg is public')
  assert(
    classifyRequestUrl('/launchr_logo.svg?v=1', config) === 'public',
    'classifyRequestUrl /launchr_logo.svg?v=1 is public',
  )

  const nginx = emitNginxLocations(config)
  assert(nginx.includes(CACHE_CONTROL.entry), 'emitNginxLocations contains entry Cache-Control')
  assert(nginx.includes(CACHE_CONTROL.public), 'emitNginxLocations contains public Cache-Control')
  assert(nginx.includes(CACHE_CONTROL.hashed), 'emitNginxLocations contains hashed Cache-Control')
  const hashedBlock = hashedLocationBlock(nginx)
  assert(
    hashedBlock.includes('try_files $uri =404'),
    'hashed location has try_files $uri =404',
  )

  const committed = readFileSync(nginxPath, 'utf8')
  assert(committed === nginx, 'spa-cache.nginx.conf matches emitNginxLocations')

  if (distNeedsBuild()) {
    await run('npm', ['run', 'build'])
  }

  const hashedFiles = existsSync(assetsDir)
    ? readdirSync(assetsDir).filter((name) => !name.startsWith('.'))
    : []
  assert(hashedFiles.length > 0, 'hashed live check: dist/assets has no files')

  let preview
  try {
    preview = startPreview()
    await waitForPreview(preview, 20000)

    const rootHeader = await cacheControlFor('/')
    assert(
      rootHeader === CACHE_CONTROL.entry,
      `preview / Cache-Control is ${CACHE_CONTROL.entry}, got ${rootHeader}`,
    )

    const spaHeader = await cacheControlFor('/hr/employees')
    assert(
      spaHeader === CACHE_CONTROL.entry,
      `preview /hr/employees Cache-Control is ${CACHE_CONTROL.entry}, got ${spaHeader}`,
    )

    const hashedPath = '/assets/' + hashedFiles[0]
    const hashedHeader = await cacheControlFor(hashedPath)
    assert(
      hashedHeader === CACHE_CONTROL.hashed,
      `preview ${hashedPath} Cache-Control is ${CACHE_CONTROL.hashed}, got ${hashedHeader}`,
    )

    const publicHeader = await cacheControlFor('/launchr_logo.svg')
    assert(
      publicHeader === CACHE_CONTROL.public,
      `preview /launchr_logo.svg Cache-Control is ${CACHE_CONTROL.public}, got ${publicHeader}`,
    )
  } finally {
    killPreview(preview)
  }
}

await main()
