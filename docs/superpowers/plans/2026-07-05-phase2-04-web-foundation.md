# Binder Phase 2 — Plan 04: Web App Foundation Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** `apps/web` — Next.js 15 웹 앱의 기반: 세션 인증(RBAC), ko/en i18n, 앱 셸, 실데이터 대시보드(fixity 위젯·최근 인제스트·컬렉션 합계), 인증 게이트된 REST `/api/v1` 기반, Playwright E2E 1본. **브라우즈·상세·context browser는 Plan 05**(시리즈 재번호: 05 UI Core II, 06 Integrations, 07 Admin & Sync).

**Architecture:** App Router + Server Components가 `@binder/db` 쿼리를 직접 호출(내부 데이터 접근은 서버 컴포넌트/서버 액션, 외부 소비용은 `/api/v1` Route Handlers). 세션은 DB 테이블 + httpOnly 쿠키(직접 구현 — 외부 인증 라이브러리 없이 argon2+세션토큰). i18n은 next-intl(경로 프리픽스 없이 쿠키 기반 로케일).

**Tech Stack:** next@^15, react@^19, next-intl@^3, tailwindcss@^4(@tailwindcss/postcss), @playwright/test(devDep). DB·도메인은 기존 @binder/db·@binder/core.

**설계 근거:** 설계서 §2(모노레포)·§6(UI 대시보드행)·§7(인증·권한). Plan 03까지의 데이터 층(HEAD 기준 마이그레이션 전부, 데모 시더에 보존 데이터 포함)이 전제.

## Global Constraints

- Repo: `/Users/daejinan/claude/moma-binder/binder-next` (main). Plan 03 완료 상태에서 시작(레저 확인).
- 기존 관례 전부 유지: ESM+TS strict, `.js` 임포트(스키마 내부만 확장자 없음), snake_case/timestamptz, soft-delete, DbExecutor, conventional commits, `pnpm typecheck` 그린 게이트.
- apps/web은 **Next.js 기본 관례**(App Router, `src/` 없이 `app/` 루트) + tsconfig는 Next가 요구하는 옵션(모듈 해석 Bundler, jsx preserve)을 자체 tsconfig로 가짐 — tsconfig.base 상속하되 override.
- 웹 앱의 DB 접속은 서버 전용 모듈 `apps/web/lib/db.ts` 한 곳(싱글턴) — 클라이언트 컴포넌트에서 import 금지(`server-only` 패키지로 강제).
- 비밀값: `SESSION_SECRET` 불필요(랜덤 토큰 + DB 조회 방식), 쿠키는 httpOnly+sameSite=lax, `secure`는 프로덕션만.
- 세션 만료 30일, 롤링 갱신은 하지 않음(YAGNI — Plan 07 관리 화면에서 재고).
- UI 텍스트는 전부 next-intl 메시지 카탈로그(ko.json/en.json) 경유 — 하드코딩 금지.
- E2E는 dev 서버 + 시드된 dev DB 전제(별도 테스트 DB 아님) — `pnpm e2e`.

---

### Task 1: apps/web 스캐폴드 (Next 15 + Tailwind 4 + turbo 연결)

**Files:**
- Create: `apps/web/package.json`, `apps/web/tsconfig.json`, `apps/web/next.config.ts`, `apps/web/postcss.config.mjs`, `apps/web/app/globals.css`, `apps/web/app/layout.tsx`, `apps/web/app/page.tsx`, `apps/web/lib/db.ts`, `apps/web/.env.local.example`
- Modify: `binder-next/turbo.json`(dev 태스크), `binder-next/package.json`(dev 스크립트)

**Interfaces:**
- Consumes: `@binder/db`(createDb), `@binder/core`.
- Produces: `pnpm --filter web dev`(:3000), `pnpm --filter web build` 그린; `getDb()` 서버 싱글턴(`apps/web/lib/db.ts`) — 이후 태스크 전부가 사용.

- [ ] **Step 1: 패키지·설정 파일 작성**

`apps/web/package.json`:
```json
{
  "name": "web",
  "version": "0.1.0",
  "private": true,
  "type": "module",
  "scripts": {
    "dev": "next dev",
    "build": "next build",
    "start": "next start",
    "typecheck": "tsc --noEmit",
    "test": "vitest run"
  },
  "dependencies": {
    "@binder/core": "workspace:*",
    "@binder/db": "workspace:*",
    "next": "^15.1.0",
    "next-intl": "^3.26.0",
    "react": "^19.0.0",
    "react-dom": "^19.0.0",
    "server-only": "^0.0.1"
  },
  "devDependencies": {
    "@tailwindcss/postcss": "^4.0.0",
    "@types/react": "^19.0.0",
    "@types/react-dom": "^19.0.0",
    "tailwindcss": "^4.0.0",
    "typescript": "^5.6.0",
    "vitest": "^2.1.0"
  }
}
```

`apps/web/tsconfig.json`:
```json
{
  "extends": "../../tsconfig.base.json",
  "compilerOptions": {
    "module": "ESNext",
    "moduleResolution": "Bundler",
    "jsx": "preserve",
    "lib": ["dom", "dom.iterable", "esnext"],
    "types": ["node", "react", "react-dom"],
    "allowJs": true,
    "noEmit": true,
    "incremental": true,
    "plugins": [{ "name": "next" }],
    "paths": { "@/*": ["./*"] }
  },
  "include": ["next-env.d.ts", "**/*.ts", "**/*.tsx", ".next/types/**/*.ts"],
  "exclude": ["node_modules", ".next"]
}
```

`apps/web/next.config.ts`:
```ts
import type { NextConfig } from 'next';
import createNextIntlPlugin from 'next-intl/plugin';

const withNextIntl = createNextIntlPlugin('./lib/i18n.ts');

const nextConfig: NextConfig = {
  transpilePackages: ['@binder/db', '@binder/core'],
};

export default withNextIntl(nextConfig);
```

`apps/web/postcss.config.mjs`:
```js
export default { plugins: { '@tailwindcss/postcss': {} } };
```

`apps/web/app/globals.css`:
```css
@import "tailwindcss";
```

`apps/web/.env.local.example`:
```
DATABASE_URL=postgres://binder:binder@localhost:15432/binder
```

- [ ] **Step 2: 루트 레이아웃·홈·DB 싱글턴**

`apps/web/lib/db.ts`:
```ts
import 'server-only';
import { createDb } from '@binder/db';

const url = process.env.DATABASE_URL ?? 'postgres://binder:binder@localhost:15432/binder';

const globalForDb = globalThis as unknown as { __binderDb?: ReturnType<typeof createDb> };

export function getDb() {
  globalForDb.__binderDb ??= createDb(url);
  return globalForDb.__binderDb.db;
}
```

`apps/web/app/layout.tsx` (임시 — Task 4에서 셸로 교체):
```tsx
import './globals.css';
import type { ReactNode } from 'react';

export const metadata = { title: 'Binder' };

export default function RootLayout({ children }: { children: ReactNode }) {
  return (
    <html lang="ko">
      <body className="min-h-screen bg-neutral-50 text-neutral-900">{children}</body>
    </html>
  );
}
```

`apps/web/app/page.tsx` (임시):
```tsx
export default function Home() {
  return <main className="p-8 text-xl font-semibold">Binder</main>;
}
```

`lib/i18n.ts`는 Task 4에서 작성 — Step 3의 빌드가 요구하므로 **이 태스크에서 최소 스텁 생성**:
```ts
import { getRequestConfig } from 'next-intl/server';

export default getRequestConfig(async () => ({
  locale: 'ko',
  messages: {},
}));
```

turbo.json tasks에 추가: `"dev": { "cache": false, "persistent": true }`. 루트 package.json scripts에 `"dev": "turbo run dev --filter=web"`.

- [ ] **Step 3: 설치·빌드·기동 검증**

Run:
```bash
cd /Users/daejinan/claude/moma-binder/binder-next && pnpm install
pnpm --filter web build
pnpm typecheck
```
Expected: 빌드 성공, typecheck 전 패키지 그린. (dev 기동 확인은 `pnpm --filter web dev`를 백그라운드로 띄워 `curl -s localhost:3000 | grep Binder` 후 종료.)

- [ ] **Step 4: 커밋**

```bash
git add -A && git commit -m "feat(web): scaffold next 15 app with tailwind 4 and server-only db singleton"
```

---

### Task 2: 세션 스키마 + 인증 쿼리 (@binder/db) — TDD

**Files:**
- Create: `packages/db/src/schema/sessions.ts`, `packages/db/src/queries/auth.ts`
- Modify: `packages/db/src/schema/index.ts`, `packages/db/src/index.ts`
- Test: `packages/db/test/auth-queries.test.ts`

**Interfaces:**
- Consumes: users(Plan 01), verifyPassword(@binder/core).
- Produces:
  - 테이블 `sessions`: id text PK(토큰 해시 아님 — 랜덤 256bit hex 그대로; DB 유출 시 세션 탈취 가능하나 재발급으로 대응, Plan 07 재고 주석), user_id →users CASCADE NOT NULL, expires_at timestamptz NOT NULL, created_at defaultNow; 인덱스 (user_id)
  - `authenticate(db, email, password): Promise<{ userId: number } | null>` — active 사용자만, verifyPassword
  - `createSession(db, userId, ttlDays=30): Promise<{ token: string; expiresAt: Date }>` — `crypto.randomBytes(32).toString('hex')`
  - `validateSession(db, token): Promise<{ userId: number; username: string; permissions: string[] } | null>` — 만료 검사 + 만료 행 지연 삭제, roles 권한 합집합
  - `destroySession(db, token): Promise<void>`

- [ ] **Step 1: 실패하는 테스트 작성**

`packages/db/test/auth-queries.test.ts`:
```ts
import { afterAll, beforeAll, describe, expect, it } from 'vitest';
import { hashPassword } from '@binder/core';
import { testDb, truncate } from './helpers.js';
import { roles, userRoles, users } from '../src/schema/index.js';
import { seedRoles } from '../src/seed/seed-roles.js';
import { authenticate, createSession, destroySession, validateSession } from '../src/queries/auth.js';
import { eq } from 'drizzle-orm';

const { db, sql } = testDb();
let userId: number;

beforeAll(async () => {
  await truncate(sql, 'sessions', 'user_roles', 'users', 'roles');
  await seedRoles(db);
  const [u] = await db.insert(users).values({
    username: 'curator', email: 'curator@example.com', passwordHash: await hashPassword('secret-pw-1'),
  }).returning();
  userId = u.id;
  const [editor] = await db.select().from(roles).where(eq(roles.name, 'editor'));
  await db.insert(userRoles).values({ userId, roleId: editor.id });
});
afterAll(() => sql.end());

describe('authenticate', () => {
  it('returns userId for correct credentials, null otherwise', async () => {
    expect(await authenticate(db, 'curator@example.com', 'secret-pw-1')).toEqual({ userId });
    expect(await authenticate(db, 'curator@example.com', 'wrong')).toBeNull();
    expect(await authenticate(db, 'nobody@example.com', 'secret-pw-1')).toBeNull();
  });

  it('rejects inactive users', async () => {
    await db.update(users).set({ active: false }).where(eq(users.id, userId));
    expect(await authenticate(db, 'curator@example.com', 'secret-pw-1')).toBeNull();
    await db.update(users).set({ active: true }).where(eq(users.id, userId));
  });
});

describe('sessions', () => {
  it('creates, validates (with permissions) and destroys a session', async () => {
    const { token } = await createSession(db, userId);
    const s = await validateSession(db, token);
    expect(s?.username).toBe('curator');
    expect(s?.permissions).toContain('records:read');
    await destroySession(db, token);
    expect(await validateSession(db, token)).toBeNull();
  });

  it('rejects expired sessions and deletes them lazily', async () => {
    const { token } = await createSession(db, userId, -1); // 이미 만료
    expect(await validateSession(db, token)).toBeNull();
    const rows = await sql`SELECT 1 FROM sessions WHERE id = ${token}`;
    expect(rows).toHaveLength(0);
  });
});
```

- [ ] **Step 2: 실패 확인**

Run: `pnpm --filter @binder/db test` → FAIL.

- [ ] **Step 3: 구현 + 마이그레이션**

`packages/db/src/schema/sessions.ts`:
```ts
import { integer, pgTable, text, timestamp, index } from 'drizzle-orm/pg-core';
import { users } from './users';

// 토큰 원문 저장(해시 아님): DB 접근 = 이미 전권 전제. Plan 07에서 해시 저장 재고.
export const sessions = pgTable('sessions', {
  id: text('id').primaryKey(),
  userId: integer('user_id').notNull().references(() => users.id, { onDelete: 'cascade' }),
  expiresAt: timestamp('expires_at', { withTimezone: true }).notNull(),
  createdAt: timestamp('created_at', { withTimezone: true }).notNull().defaultNow(),
}, (t) => [index('sessions_user_ix').on(t.userId)]);
```

`packages/db/src/queries/auth.ts`:
```ts
import { randomBytes } from 'node:crypto';
import { and, eq, inArray } from 'drizzle-orm';
import type { PostgresJsDatabase } from 'drizzle-orm/postgres-js';
import { verifyPassword } from '@binder/core';
import { roles, sessions, userRoles, users } from '../schema/index.js';

export async function authenticate(
  db: PostgresJsDatabase,
  email: string,
  password: string,
): Promise<{ userId: number } | null> {
  const [u] = await db.select().from(users).where(and(eq(users.email, email), eq(users.active, true)));
  if (!u) return null;
  return (await verifyPassword(u.passwordHash, password)) ? { userId: u.id } : null;
}

export async function createSession(
  db: PostgresJsDatabase,
  userId: number,
  ttlDays = 30,
): Promise<{ token: string; expiresAt: Date }> {
  const token = randomBytes(32).toString('hex');
  const expiresAt = new Date(Date.now() + ttlDays * 24 * 60 * 60 * 1000);
  await db.insert(sessions).values({ id: token, userId, expiresAt });
  return { token, expiresAt };
}

export async function validateSession(
  db: PostgresJsDatabase,
  token: string,
): Promise<{ userId: number; username: string; permissions: string[] } | null> {
  const [row] = await db.select({
    userId: sessions.userId, expiresAt: sessions.expiresAt, username: users.username,
  }).from(sessions).innerJoin(users, eq(sessions.userId, users.id)).where(eq(sessions.id, token));
  if (!row) return null;
  if (row.expiresAt.getTime() < Date.now()) {
    await db.delete(sessions).where(eq(sessions.id, token));
    return null;
  }
  const roleIds = (await db.select({ roleId: userRoles.roleId }).from(userRoles)
    .where(eq(userRoles.userId, row.userId))).map((r) => r.roleId);
  const permissionRows = roleIds.length
    ? await db.select({ permissions: roles.permissions }).from(roles).where(inArray(roles.id, roleIds))
    : [];
  const permissions = [...new Set(permissionRows.flatMap((r) => r.permissions))];
  return { userId: row.userId, username: row.username, permissions };
}

export async function destroySession(db: PostgresJsDatabase, token: string): Promise<void> {
  await db.delete(sessions).where(eq(sessions.id, token));
}
```

`schema/index.ts`에 `export { sessions } from './sessions.js';`, `src/index.ts`에 auth 4함수 export 추가.

Run: `pnpm --filter @binder/db db:generate` → sessions 마이그레이션.

- [ ] **Step 4: 그린 + 커밋**

Run: `pnpm --filter @binder/db test && pnpm typecheck` → PASS. `pnpm --filter @binder/db db:migrate`.

```bash
git add -A && git commit -m "feat(db): db-backed sessions with role permission resolution"
```

---

### Task 3: 로그인/로그아웃 + 라우트 보호 (apps/web)

**Files:**
- Create: `apps/web/lib/session.ts`, `apps/web/lib/actions/auth.ts`, `apps/web/app/login/page.tsx`, `apps/web/app/(app)/layout.tsx`
- Modify: `apps/web/app/page.tsx` → `apps/web/app/(app)/page.tsx`로 이동(보호 구역)

**Interfaces:**
- Consumes: Task 2 auth 쿼리, getDb().
- Produces:
  - `getCurrentUser(): Promise<SessionUser | null>`(React cache, cookies() 기반) — `SessionUser = { userId, username, permissions }`; `requireUser()` — 없으면 `redirect('/login')`
  - 서버 액션 `loginAction(prevState, formData)` / `logoutAction()`
  - 라우트 그룹 `(app)` 레이아웃이 `requireUser()` 호출 — 이후 모든 보호 페이지는 이 그룹에 배치
  - 쿠키명 `binder_session`

- [ ] **Step 1: 세션 헬퍼·서버 액션 작성**

`apps/web/lib/session.ts`:
```ts
import 'server-only';
import { cache } from 'react';
import { cookies } from 'next/headers';
import { redirect } from 'next/navigation';
import { validateSession } from '@binder/db';
import { getDb } from './db.js';

export type SessionUser = { userId: number; username: string; permissions: string[] };

export const COOKIE_NAME = 'binder_session';

export const getCurrentUser = cache(async (): Promise<SessionUser | null> => {
  const jar = await cookies();
  const token = jar.get(COOKIE_NAME)?.value;
  if (!token) return null;
  return validateSession(getDb(), token);
});

export async function requireUser(): Promise<SessionUser> {
  const user = await getCurrentUser();
  if (!user) redirect('/login');
  return user;
}

export function can(user: SessionUser, permission: string): boolean {
  return user.permissions.includes('*') || user.permissions.includes(permission);
}
```

`apps/web/lib/actions/auth.ts`:
```ts
'use server';

import { cookies } from 'next/headers';
import { redirect } from 'next/navigation';
import { authenticate, createSession, destroySession } from '@binder/db';
import { getDb } from '../db.js';
import { COOKIE_NAME } from '../session.js';

export type LoginState = { error?: string };

export async function loginAction(_prev: LoginState, formData: FormData): Promise<LoginState> {
  const email = String(formData.get('email') ?? '');
  const password = String(formData.get('password') ?? '');
  const db = getDb();
  const auth = await authenticate(db, email, password);
  if (!auth) return { error: 'invalid' };

  const { token, expiresAt } = await createSession(db, auth.userId);
  const jar = await cookies();
  jar.set(COOKIE_NAME, token, {
    httpOnly: true, sameSite: 'lax', secure: process.env.NODE_ENV === 'production',
    expires: expiresAt, path: '/',
  });
  redirect('/');
}

export async function logoutAction(): Promise<void> {
  const jar = await cookies();
  const token = jar.get(COOKIE_NAME)?.value;
  if (token) await destroySession(getDb(), token);
  jar.delete(COOKIE_NAME);
  redirect('/login');
}
```

- [ ] **Step 2: 로그인 페이지 + 보호 레이아웃**

`apps/web/app/login/page.tsx`:
```tsx
'use client';

import { useActionState } from 'react';
import { useTranslations } from 'next-intl';
import { loginAction, type LoginState } from '@/lib/actions/auth';

export default function LoginPage() {
  const t = useTranslations('login');
  const [state, formAction, pending] = useActionState<LoginState, FormData>(loginAction, {});
  return (
    <main className="flex min-h-screen items-center justify-center">
      <form action={formAction} className="w-80 space-y-4 rounded-lg border bg-white p-6 shadow-sm">
        <h1 className="text-xl font-semibold">Binder</h1>
        {state.error && <p className="text-sm text-red-600">{t('invalid')}</p>}
        <input name="email" type="email" required placeholder={t('email')}
          className="w-full rounded border px-3 py-2" />
        <input name="password" type="password" required placeholder={t('password')}
          className="w-full rounded border px-3 py-2" />
        <button disabled={pending} className="w-full rounded bg-blue-600 py-2 text-white disabled:opacity-50">
          {t('submit')}
        </button>
      </form>
    </main>
  );
}
```

`apps/web/app/(app)/layout.tsx` (임시 — Task 4에서 셸 확장):
```tsx
import type { ReactNode } from 'react';
import { requireUser } from '@/lib/session';

export default async function AppLayout({ children }: { children: ReactNode }) {
  await requireUser();
  return <>{children}</>;
}
```

`app/page.tsx`를 `app/(app)/page.tsx`로 이동.

- [ ] **Step 3: 수동 검증(개발 서버) + 빌드·타입체크**

Run: dev 서버 기동 후 —
```bash
curl -s -o /dev/null -w "%{http_code} %{redirect_url}\n" localhost:3000/          # 307 → /login
curl -s -c /tmp/wc.txt -X POST localhost:3000/login ...                            # 폼은 서버액션이라 curl로 어려움 — 대신:
```
서버 액션 폼은 curl 재현이 번거로우므로 **여기서는 리다이렉트 게이트만 curl로 확인**(비로그인 / → /login 307, /login 200). 로그인 플로우 자체는 Task 7 Playwright가 검증. `pnpm --filter web build && pnpm typecheck` 그린.

- [ ] **Step 4: 커밋**

```bash
git add -A && git commit -m "feat(web): session auth with login page and protected route group"
```

---

### Task 4: i18n(ko/en) + 앱 셸

**Files:**
- Create: `apps/web/messages/ko.json`, `apps/web/messages/en.json`, `apps/web/lib/actions/locale.ts`, `apps/web/components/shell/AppShell.tsx`, `apps/web/components/shell/LocaleSwitcher.tsx`, `apps/web/components/shell/UserMenu.tsx`
- Modify: `apps/web/lib/i18n.ts`(스텁 교체), `apps/web/app/layout.tsx`, `apps/web/app/(app)/layout.tsx`

**Interfaces:**
- Consumes: getCurrentUser, logoutAction.
- Produces: 로케일 쿠키 `binder_locale`('ko'|'en', 기본 ko) + `setLocaleAction`; `AppShell`(상단 바: 로고/내비 자리/LocaleSwitcher/UserMenu) — Plan 05가 내비 항목을 추가; 메시지 카탈로그 구조 `{ shell: {...}, login: {...}, dashboard: {...} }`.

- [ ] **Step 1: i18n 설정·메시지·전환 액션**

`apps/web/lib/i18n.ts` 전체 교체:
```ts
import { getRequestConfig } from 'next-intl/server';
import { cookies } from 'next/headers';

export default getRequestConfig(async () => {
  const jar = await cookies();
  const locale = jar.get('binder_locale')?.value === 'en' ? 'en' : 'ko';
  return {
    locale,
    messages: (await import(`../messages/${locale}.json`)).default,
  };
});
```

`apps/web/messages/ko.json`:
```json
{
  "shell": { "appName": "Binder", "logout": "로그아웃", "language": "언어" },
  "login": { "email": "이메일", "password": "비밀번호", "submit": "로그인", "invalid": "이메일 또는 비밀번호가 올바르지 않습니다." },
  "dashboard": {
    "title": "대시보드",
    "fixity": "무결성 감사",
    "fixityOk": "정상", "fixityFailed": "실패", "fixityNone": "기록 없음",
    "lastAudit": "최근 전체 감사", "checks24h": "최근 24시간 점검",
    "recentIngests": "최근 인제스트", "noIngests": "인제스트 이력이 없습니다",
    "collection": "컬렉션 합계", "works": "작품/기록", "aips": "AIP", "files": "파일", "totalSize": "총 용량"
  }
}
```

`apps/web/messages/en.json`:
```json
{
  "shell": { "appName": "Binder", "logout": "Log out", "language": "Language" },
  "login": { "email": "Email", "password": "Password", "submit": "Log in", "invalid": "Incorrect email or password." },
  "dashboard": {
    "title": "Dashboard",
    "fixity": "Fixity audit",
    "fixityOk": "OK", "fixityFailed": "Failed", "fixityNone": "No checks yet",
    "lastAudit": "Last repository audit", "checks24h": "Checks in last 24h",
    "recentIngests": "Recent ingests", "noIngests": "No ingest activity yet",
    "collection": "Collection totals", "works": "Works/records", "aips": "AIPs", "files": "Files", "totalSize": "Total size"
  }
}
```

`apps/web/lib/actions/locale.ts`:
```ts
'use server';

import { cookies } from 'next/headers';
import { revalidatePath } from 'next/cache';

export async function setLocaleAction(locale: 'ko' | 'en'): Promise<void> {
  const jar = await cookies();
  jar.set('binder_locale', locale, { path: '/', sameSite: 'lax' });
  revalidatePath('/', 'layout');
}
```

- [ ] **Step 2: 루트 레이아웃에 NextIntlClientProvider, 셸 컴포넌트**

`apps/web/app/layout.tsx` 전체 교체:
```tsx
import './globals.css';
import type { ReactNode } from 'react';
import { NextIntlClientProvider } from 'next-intl';
import { getLocale, getMessages } from 'next-intl/server';

export const metadata = { title: 'Binder' };

export default async function RootLayout({ children }: { children: ReactNode }) {
  const locale = await getLocale();
  const messages = await getMessages();
  return (
    <html lang={locale}>
      <body className="min-h-screen bg-neutral-50 text-neutral-900">
        <NextIntlClientProvider messages={messages}>{children}</NextIntlClientProvider>
      </body>
    </html>
  );
}
```

`apps/web/components/shell/LocaleSwitcher.tsx`:
```tsx
'use client';

import { useLocale } from 'next-intl';
import { setLocaleAction } from '@/lib/actions/locale';

export function LocaleSwitcher() {
  const locale = useLocale();
  const next = locale === 'ko' ? 'en' : 'ko';
  return (
    <button onClick={() => setLocaleAction(next)} className="rounded border px-2 py-1 text-sm">
      {next.toUpperCase()}
    </button>
  );
}
```

`apps/web/components/shell/UserMenu.tsx`:
```tsx
import { useTranslations } from 'next-intl';
import { logoutAction } from '@/lib/actions/auth';

export function UserMenu({ username }: { username: string }) {
  const t = useTranslations('shell');
  return (
    <form action={logoutAction} className="flex items-center gap-3">
      <span className="text-sm text-neutral-600">{username}</span>
      <button className="rounded border px-2 py-1 text-sm">{t('logout')}</button>
    </form>
  );
}
```

`apps/web/components/shell/AppShell.tsx`:
```tsx
import type { ReactNode } from 'react';
import { useTranslations } from 'next-intl';
import { LocaleSwitcher } from './LocaleSwitcher';
import { UserMenu } from './UserMenu';

export function AppShell({ username, children }: { username: string; children: ReactNode }) {
  const t = useTranslations('shell');
  return (
    <div className="min-h-screen">
      <header className="flex items-center justify-between border-b bg-white px-6 py-3">
        <div className="flex items-center gap-8">
          <span className="text-lg font-bold">{t('appName')}</span>
          <nav className="flex gap-4 text-sm text-neutral-600">{/* Plan 05: 내비 항목 */}</nav>
        </div>
        <div className="flex items-center gap-3">
          <LocaleSwitcher />
          <UserMenu username={username} />
        </div>
      </header>
      <main className="mx-auto max-w-6xl p-6">{children}</main>
    </div>
  );
}
```

`apps/web/app/(app)/layout.tsx` 전체 교체:
```tsx
import type { ReactNode } from 'react';
import { requireUser } from '@/lib/session';
import { AppShell } from '@/components/shell/AppShell';

export default async function AppLayout({ children }: { children: ReactNode }) {
  const user = await requireUser();
  return <AppShell username={user.username}>{children}</AppShell>;
}
```

- [ ] **Step 3: 빌드·타입체크 + 커밋**

Run: `pnpm --filter web build && pnpm typecheck` → 그린.

```bash
git add -A && git commit -m "feat(web): cookie-based ko/en i18n and authenticated app shell"
```

---

### Task 5: 대시보드 쿼리 (@binder/db) — TDD

**Files:**
- Create: `packages/db/src/queries/dashboard.ts`
- Modify: `packages/db/src/index.ts`
- Test: `packages/db/test/dashboard-queries.test.ts`

**Interfaces:**
- Consumes: aips/files/fixityReports/nodes.
- Produces:
  - `getFixitySummary(db): Promise<{ status: 'ok' | 'failed' | 'none'; lastSessionCompletedAt: Date | null; checksLast24h: number }>` — 최신 세션(session_uuid별 max(completed_at)) 내 실패 유무로 status, 24시간 내 리포트 수
  - `getRecentAips(db, limit=10): Promise<Array<{ id, uuid, filename, sizeBytes, createdAt, rootNodeId, rootTitle: I18nText }>>` — live만, created_at desc, 루트 노드 제목 조인
  - `getCollectionTotals(db): Promise<{ works: number; aips: number; files: number; totalSizeBytes: number }>` — 루트 nodes(parent IS NULL, live) 수 / live aips / live files / live aips size 합

- [ ] **Step 1: 실패하는 테스트 작성**

`packages/db/test/dashboard-queries.test.ts`:
```ts
import { afterAll, beforeAll, describe, expect, it } from 'vitest';
import { testDb, truncate } from './helpers.js';
import { collectionProfiles } from '../src/schema/index.js';
import { seedTaxonomies, findTermId } from '../src/seed/seed-taxonomies.js';
import { createNode } from '../src/queries/nodes.js';
import { createAip } from '../src/queries/aips.js';
import { registerFixityReport } from '../src/queries/fixity.js';
import { getCollectionTotals, getFixitySummary, getRecentAips } from '../src/queries/dashboard.js';

const { db, sql } = testDb();
const U1 = '33333333-0000-0000-0000-000000000001';
const U2 = '33333333-0000-0000-0000-000000000002';

beforeAll(async () => {
  await truncate(sql, 'search_docs', 'fixity_recoveries', 'fixity_reports', 'ingest_jobs', 'files', 'aip_copies', 'aips', 'storage_backends', 'audit_log', 'node_rights', 'rights_acts', 'rights', 'notes', 'relations', 'events', 'agents', 'node_terms', 'nodes', 'collection_profile_versions', 'collection_profiles', 'terms', 'taxonomies');
  await seedTaxonomies(db);
  const [p] = await db.insert(collectionProfiles).values({ name: { ko: '기본' } }).returning();
  const lv = await findTermId(db, 'level_of_description', 'artwork_record');
  const w = await createNode(db, { kind: 'work', levelTermId: lv, profileId: p.id, title: { ko: '작품', en: 'Work' } });
  await createAip(db, {
    uuid: U1, nodeId: w.id, rootNodeId: w.id, origin: 'direct', sizeBytes: 700, filename: 'aip-1',
    files: [{ originalRelativePath: 'objects/a.mov', filename: 'a.mov', sizeBytes: 700 }],
  });
  await createAip(db, { uuid: U2, nodeId: w.id, rootNodeId: w.id, origin: 'direct', sizeBytes: 300, filename: 'aip-2' });
});
afterAll(() => sql.end());

describe('dashboard queries', () => {
  it('reports none before any fixity checks', async () => {
    expect((await getFixitySummary(db)).status).toBe('none');
  });

  it('summarizes the latest session and 24h count', async () => {
    const old = new Date(Date.now() - 48 * 3600 * 1000);
    const now = new Date();
    await registerFixityReport(db, { aipUuid: U1, success: true, sessionUuid: '44444444-0000-0000-0000-000000000001', startedAt: old, completedAt: old });
    await registerFixityReport(db, { aipUuid: U1, success: true, sessionUuid: '44444444-0000-0000-0000-000000000002', startedAt: now, completedAt: now });
    await registerFixityReport(db, { aipUuid: U2, success: false, sessionUuid: '44444444-0000-0000-0000-000000000002', startedAt: now, completedAt: now });

    const s = await getFixitySummary(db);
    expect(s.status).toBe('failed');        // 최신 세션에 실패 포함
    expect(s.checksLast24h).toBe(2);
    expect(s.lastSessionCompletedAt).not.toBeNull();
  });

  it('lists recent aips with root titles', async () => {
    const rows = await getRecentAips(db, 5);
    expect(rows).toHaveLength(2);
    expect(rows[0].rootTitle.ko).toBe('작품');
    expect(rows[0].createdAt.getTime()).toBeGreaterThanOrEqual(rows[1].createdAt.getTime());
  });

  it('computes collection totals from live rows', async () => {
    const t = await getCollectionTotals(db);
    expect(t).toEqual({ works: 1, aips: 2, files: 1, totalSizeBytes: 1000 });
  });
});
```

- [ ] **Step 2: 실패 확인**

Run: `pnpm --filter @binder/db test` → FAIL.

- [ ] **Step 3: 구현**

`packages/db/src/queries/dashboard.ts`:
```ts
import { sql } from 'drizzle-orm';
import type { PostgresJsDatabase } from 'drizzle-orm/postgres-js';
import type { I18nText } from '../types.js';

export async function getFixitySummary(db: PostgresJsDatabase): Promise<{
  status: 'ok' | 'failed' | 'none';
  lastSessionCompletedAt: Date | null;
  checksLast24h: number;
}> {
  const [latest] = (await db.execute(sql`
    SELECT session_uuid, max(completed_at) AS completed_at, bool_and(success) AS all_ok
    FROM fixity_reports
    WHERE session_uuid IS NOT NULL
    GROUP BY session_uuid
    ORDER BY max(completed_at) DESC NULLS LAST
    LIMIT 1
  `)) as unknown as Array<{ session_uuid: string; completed_at: Date; all_ok: boolean }>;

  const [{ c }] = (await db.execute(sql`
    SELECT count(*)::int AS c FROM fixity_reports WHERE completed_at > now() - interval '24 hours'
  `)) as unknown as Array<{ c: number }>;

  if (!latest) return { status: 'none', lastSessionCompletedAt: null, checksLast24h: Number(c) };
  return {
    status: latest.all_ok ? 'ok' : 'failed',
    lastSessionCompletedAt: latest.completed_at,
    checksLast24h: Number(c),
  };
}

export async function getRecentAips(db: PostgresJsDatabase, limit = 10): Promise<Array<{
  id: number; uuid: string; filename: string | null; sizeBytes: number;
  createdAt: Date; rootNodeId: number; rootTitle: I18nText;
}>> {
  const rows = (await db.execute(sql`
    SELECT a.id, a.uuid, a.filename, a.size_bytes, a.created_at, a.root_node_id, n.title
    FROM aips a JOIN nodes n ON n.id = a.root_node_id
    WHERE a.deleted_at IS NULL
    ORDER BY a.created_at DESC
    LIMIT ${limit}
  `)) as unknown as Array<Record<string, unknown>>;
  return rows.map((r) => ({
    id: Number(r.id), uuid: String(r.uuid), filename: r.filename === null ? null : String(r.filename),
    sizeBytes: Number(r.size_bytes), createdAt: r.created_at as Date,
    rootNodeId: Number(r.root_node_id), rootTitle: r.title as I18nText,
  }));
}

export async function getCollectionTotals(db: PostgresJsDatabase): Promise<{
  works: number; aips: number; files: number; totalSizeBytes: number;
}> {
  const [r] = (await db.execute(sql`
    SELECT
      (SELECT count(*)::int FROM nodes WHERE parent_id IS NULL AND deleted_at IS NULL) AS works,
      (SELECT count(*)::int FROM aips WHERE deleted_at IS NULL) AS aips,
      (SELECT count(*)::int FROM files WHERE deleted_at IS NULL) AS files,
      (SELECT COALESCE(sum(size_bytes), 0) FROM aips WHERE deleted_at IS NULL) AS total
  `)) as unknown as Array<{ works: number; aips: number; files: number; total: string | number }>;
  return { works: Number(r.works), aips: Number(r.aips), files: Number(r.files), totalSizeBytes: Number(r.total) };
}
```

`src/index.ts`에 3함수 export 추가.

- [ ] **Step 4: 그린 + 커밋**

Run: `pnpm --filter @binder/db test && pnpm typecheck` → PASS.

```bash
git add -A && git commit -m "feat(db): dashboard queries for fixity summary, recent aips and collection totals"
```

---

### Task 6: 대시보드 페이지 (apps/web)

**Files:**
- Create: `apps/web/app/(app)/page.tsx`(교체), `apps/web/components/dashboard/FixityCard.tsx`, `apps/web/components/dashboard/RecentIngests.tsx`, `apps/web/components/dashboard/CollectionTotals.tsx`, `apps/web/lib/format.ts`

**Interfaces:**
- Consumes: Task 5 쿼리, pickI18n(@binder/core), 로케일(getLocale).
- Produces: `/` 대시보드 — fixity 상태 배지(ok 초록/failed 빨강/none 회색), 최근 인제스트 테이블(AIP명/소속 레코드 제목(로케일)/크기/일시), 컬렉션 합계 카드. `formatBytes(n)` 헬퍼(소수1자리 B/KB/MB/GB/TB).

- [ ] **Step 1: 헬퍼·컴포넌트·페이지 작성**

`apps/web/lib/format.ts`:
```ts
export function formatBytes(n: number): string {
  if (n < 1024) return `${n} B`;
  const units = ['KB', 'MB', 'GB', 'TB'];
  let v = n;
  let i = -1;
  do { v /= 1024; i++; } while (v >= 1024 && i < units.length - 1);
  return `${v.toFixed(1)} ${units[i]}`;
}
```

`apps/web/components/dashboard/FixityCard.tsx`:
```tsx
import { useTranslations } from 'next-intl';

const COLORS = { ok: 'bg-green-100 text-green-800', failed: 'bg-red-100 text-red-800', none: 'bg-neutral-100 text-neutral-600' } as const;

export function FixityCard({ status, lastAt, checks24h }: {
  status: 'ok' | 'failed' | 'none'; lastAt: string | null; checks24h: number;
}) {
  const t = useTranslations('dashboard');
  const label = status === 'ok' ? t('fixityOk') : status === 'failed' ? t('fixityFailed') : t('fixityNone');
  return (
    <section className="rounded-lg border bg-white p-4">
      <h2 className="mb-3 text-sm font-semibold text-neutral-500">{t('fixity')}</h2>
      <div className="flex items-center gap-6">
        <span className={`rounded px-2 py-1 text-sm font-medium ${COLORS[status]}`} data-testid="fixity-status">{label}</span>
        <div className="text-sm text-neutral-600">
          <div>{t('lastAudit')}: {lastAt ?? '—'}</div>
          <div>{t('checks24h')}: {checks24h}</div>
        </div>
      </div>
    </section>
  );
}
```

`apps/web/components/dashboard/RecentIngests.tsx`:
```tsx
import { useTranslations } from 'next-intl';

export type IngestRow = { id: number; name: string; partOf: string; size: string; at: string };

export function RecentIngests({ rows }: { rows: IngestRow[] }) {
  const t = useTranslations('dashboard');
  return (
    <section className="rounded-lg border bg-white p-4">
      <h2 className="mb-3 text-sm font-semibold text-neutral-500">{t('recentIngests')}</h2>
      {rows.length === 0 ? (
        <p className="text-sm text-neutral-500">{t('noIngests')}</p>
      ) : (
        <table className="w-full text-sm">
          <tbody>
            {rows.map((r) => (
              <tr key={r.id} className="border-t">
                <td className="py-2 font-medium">{r.name}</td>
                <td className="py-2 text-neutral-600">{r.partOf}</td>
                <td className="py-2 text-right text-neutral-600">{r.size}</td>
                <td className="py-2 text-right text-neutral-500">{r.at}</td>
              </tr>
            ))}
          </tbody>
        </table>
      )}
    </section>
  );
}
```

`apps/web/components/dashboard/CollectionTotals.tsx`:
```tsx
import { useTranslations } from 'next-intl';

export function CollectionTotals({ works, aips, files, totalSize }: {
  works: number; aips: number; files: number; totalSize: string;
}) {
  const t = useTranslations('dashboard');
  const items = [
    [t('works'), String(works)], [t('aips'), String(aips)],
    [t('files'), String(files)], [t('totalSize'), totalSize],
  ] as const;
  return (
    <section className="rounded-lg border bg-white p-4">
      <h2 className="mb-3 text-sm font-semibold text-neutral-500">{t('collection')}</h2>
      <dl className="grid grid-cols-2 gap-3 text-sm">
        {items.map(([k, v]) => (
          <div key={k}><dt className="text-neutral-500">{k}</dt><dd className="text-lg font-semibold">{v}</dd></div>
        ))}
      </dl>
    </section>
  );
}
```

`apps/web/app/(app)/page.tsx` 전체 교체:
```tsx
import { getLocale, getTranslations } from 'next-intl/server';
import { pickI18n, type Culture } from '@binder/core';
import { getCollectionTotals, getFixitySummary, getRecentAips } from '@binder/db';
import { getDb } from '@/lib/db';
import { formatBytes } from '@/lib/format';
import { FixityCard } from '@/components/dashboard/FixityCard';
import { RecentIngests } from '@/components/dashboard/RecentIngests';
import { CollectionTotals } from '@/components/dashboard/CollectionTotals';

export const dynamic = 'force-dynamic';

export default async function DashboardPage() {
  const db = getDb();
  const locale = (await getLocale()) as Culture;
  const t = await getTranslations('dashboard');
  const [fixity, recent, totals] = await Promise.all([
    getFixitySummary(db), getRecentAips(db, 10), getCollectionTotals(db),
  ]);

  return (
    <div className="space-y-6">
      <h1 className="text-2xl font-bold">{t('title')}</h1>
      <div className="grid gap-6 md:grid-cols-2">
        <FixityCard
          status={fixity.status}
          lastAt={fixity.lastSessionCompletedAt?.toLocaleString(locale) ?? null}
          checks24h={fixity.checksLast24h}
        />
        <CollectionTotals
          works={totals.works} aips={totals.aips} files={totals.files}
          totalSize={formatBytes(totals.totalSizeBytes)}
        />
      </div>
      <RecentIngests rows={recent.map((r) => ({
        id: r.id,
        name: r.filename ?? r.uuid.slice(0, 8),
        partOf: pickI18n(r.rootTitle, locale, 'ko'),
        size: formatBytes(r.sizeBytes),
        at: r.createdAt.toLocaleDateString(locale),
      }))} />
    </div>
  );
}
```

- [ ] **Step 2: 검증(개발 서버 + 시드 데이터) + 커밋**

Run: dev DB에 시드가 있는 상태(`db:seed` + `db:seed-demo`)에서 dev 서버 기동 — 로그인 계정이 필요하므로 **시드에 admin 유저가 없다면** 임시 확인용으로 psql로 유저 1명 생성하는 스크립트가 필요해진다 → **이 태스크에 포함**: `packages/db/scripts/create-user.ts`(email/username/password/role 인자, argon2 해시, upsert) + package.json `db:create-user`. 코드:
```ts
import { eq } from 'drizzle-orm';
import { hashPassword } from '@binder/core';
import { createDb } from '../src/client.js';
import { roles, userRoles, users } from '../src/schema/index.js';

const [username, email, password, roleName = 'admin'] = process.argv.slice(2);
if (!username || !email || !password) {
  console.error('usage: db:create-user <username> <email> <password> [role]');
  process.exit(1);
}
const { db, sql } = createDb(process.env.DATABASE_URL ?? 'postgres://binder:binder@localhost:15432/binder');
const passwordHash = await hashPassword(password);
const [u] = await db.insert(users).values({ username, email, passwordHash })
  .onConflictDoUpdate({ target: users.email, set: { passwordHash } }).returning();
const [role] = await db.select().from(roles).where(eq(roles.name, roleName));
if (role) await db.insert(userRoles).values({ userId: u.id, roleId: role.id }).onConflictDoNothing();
await sql.end();
console.log(`user ready: ${username} (${roleName})`);
```
검증: `pnpm --filter @binder/db db:create-user demo demo@example.com demo admin` 후 브라우저/HTTP로 로그인 → `/`가 fixity OK·인제스트 3건·합계를 렌더(개발 서버 로그와 curl -b 쿠키로 확인 가능한 수준까지). `pnpm --filter web build && pnpm typecheck && pnpm test` 그린.

- [ ] **Step 3: 커밋**

```bash
git add -A && git commit -m "feat(web): live dashboard with fixity, recent ingests and collection totals"
```

---

### Task 7: REST /api/v1 기반 + Playwright E2E

**Files:**
- Create: `apps/web/lib/api.ts`, `apps/web/app/api/v1/health/route.ts`, `apps/web/app/api/v1/nodes/route.ts`
- Create: `apps/web/playwright.config.ts`, `apps/web/e2e/login-dashboard.spec.ts`
- Modify: `apps/web/package.json`(@playwright/test devDep + e2e 스크립트), `binder-next/package.json`(e2e 스크립트), `README.md`

**Interfaces:**
- Consumes: validateSession, searchEntities, nodes 스키마.
- Produces:
  - `withAuth(handler)` 래퍼 — Authorization: Bearer <token> 또는 세션 쿠키로 validateSession; 실패 시 401 JSON
  - `GET /api/v1/health` → `{ ok: true }` (무인증)
  - `GET /api/v1/nodes?q=&limit=` — q 있으면 searchEntities('node')로 id 획득 후 노드 로드, 없으면 루트 노드 최신순; 응답 `{ items: [{ id, uuid, slug, kind, title, accessionNumber }], total }`(limit 기본 20, 최대 100)
  - Playwright: 로그인 → 대시보드 fixity 배지 visible → 로케일 전환(EN) → 로그아웃 리다이렉트 확인. `pnpm e2e`(루트) — dev 서버 자동 기동(playwright webServer)

- [ ] **Step 1: API 래퍼·핸들러 작성**

`apps/web/lib/api.ts`:
```ts
import 'server-only';
import { NextResponse, type NextRequest } from 'next/server';
import { validateSession } from '@binder/db';
import { getDb } from './db.js';
import { COOKIE_NAME, type SessionUser } from './session.js';

export function withAuth(
  handler: (req: NextRequest, user: SessionUser) => Promise<NextResponse>,
): (req: NextRequest) => Promise<NextResponse> {
  return async (req) => {
    const bearer = req.headers.get('authorization')?.replace(/^Bearer\s+/i, '');
    const cookie = req.cookies.get(COOKIE_NAME)?.value;
    const token = bearer || cookie;
    if (!token) return NextResponse.json({ error: 'unauthorized' }, { status: 401 });
    const user = await validateSession(getDb(), token);
    if (!user) return NextResponse.json({ error: 'unauthorized' }, { status: 401 });
    return handler(req, user);
  };
}
```

`apps/web/app/api/v1/health/route.ts`:
```ts
import { NextResponse } from 'next/server';

export function GET() {
  return NextResponse.json({ ok: true });
}
```

`apps/web/app/api/v1/nodes/route.ts`:
```ts
import { NextResponse, type NextRequest } from 'next/server';
import { inArray, isNull, and, desc, sql } from 'drizzle-orm';
import { schema, searchEntities } from '@binder/db';
import { getDb } from '@/lib/db';
import { withAuth } from '@/lib/api';

export const GET = withAuth(async (req: NextRequest) => {
  const db = getDb();
  const q = req.nextUrl.searchParams.get('q')?.trim() ?? '';
  const limit = Math.min(Number(req.nextUrl.searchParams.get('limit') ?? 20) || 20, 100);
  const { nodes } = schema;

  let rows;
  if (q) {
    const hits = await searchEntities(db, q, { entityType: 'node', limit });
    const ids = [...new Set(hits.map((h) => h.entityId))];
    rows = ids.length
      ? await db.select().from(nodes).where(and(inArray(nodes.id, ids), isNull(nodes.deletedAt)))
      : [];
  } else {
    rows = await db.select().from(nodes)
      .where(and(isNull(nodes.parentId), isNull(nodes.deletedAt)))
      .orderBy(desc(nodes.updatedAt)).limit(limit);
  }

  return NextResponse.json({
    items: rows.map((n) => ({
      id: n.id, uuid: n.uuid, slug: n.slug, kind: n.kind,
      title: n.title, accessionNumber: n.accessionNumber,
    })),
    total: rows.length,
  });
});
```
(참고: `schema`는 `@binder/db`가 `export * as schema`로 이미 노출.)

- [ ] **Step 2: Playwright 구성·스펙**

```bash
pnpm --filter web add -D @playwright/test && pnpm --filter web exec playwright install chromium
```

`apps/web/playwright.config.ts`:
```ts
import { defineConfig } from '@playwright/test';

export default defineConfig({
  testDir: './e2e',
  timeout: 30_000,
  use: { baseURL: 'http://localhost:3100' },
  webServer: {
    command: 'pnpm dev --port 3100',
    url: 'http://localhost:3100/api/v1/health',
    reuseExistingServer: true,
    timeout: 60_000,
  },
});
```

`apps/web/e2e/login-dashboard.spec.ts`:
```ts
import { expect, test } from '@playwright/test';

// 전제: dev DB 시드 + `pnpm --filter @binder/db db:create-user demo demo@example.com demo admin`
test('login → dashboard → locale switch → logout', async ({ page }) => {
  await page.goto('/');
  await expect(page).toHaveURL(/\/login/);

  await page.fill('input[name=email]', 'demo@example.com');
  await page.fill('input[name=password]', 'demo');
  await page.click('button[type=submit], form button');

  await expect(page).toHaveURL('/');
  await expect(page.getByTestId('fixity-status')).toBeVisible();
  await expect(page.getByText('대시보드')).toBeVisible();

  await page.getByRole('button', { name: 'EN' }).click();
  await expect(page.getByText('Dashboard')).toBeVisible();

  await page.getByRole('button', { name: /log out/i }).click();
  await expect(page).toHaveURL(/\/login/);
});
```

`apps/web/package.json` scripts에 `"e2e": "playwright test"`; 루트 package.json에 `"e2e": "pnpm --filter web e2e"`.

- [ ] **Step 3: 전체 검증 + README + 커밋**

Run:
```bash
pnpm --filter @binder/db db:create-user demo demo@example.com demo admin
curl -s localhost:3100/api/v1/health                        # (webServer로 대체 가능)
pnpm e2e                                                    # E2E 1본 그린
curl -s -H "Authorization: Bearer <db의 세션토큰>" "localhost:3100/api/v1/nodes?q=러버" | head -c 300   # 401→로그인 후 토큰으로 200 확인
pnpm --filter web build && pnpm typecheck && pnpm test
```
README에 웹 앱 섹션(기동·로그인 계정 생성·e2e 명령) 추가.

```bash
git add -A && git commit -m "feat(web): authed /api/v1 foundation and login-dashboard e2e"
```

---

## Self-Review

1. **Spec coverage (이 계획 범위)**: §2 모노레포 apps/web=T1, §7 인증·RBAC=T2·T3(+can 헬퍼), i18n ko/en=T4, §6 대시보드행=T5·T6, 외부 API 표면 최소기반=T7, E2E=T7. 브라우즈/상세/context browser/리포트/관리는 Plan 05+로 명시 이관(서두).
2. **Placeholder scan**: 전 스텝 실코드. T3의 curl 한계는 검증 방법을 명시적으로 대체(Playwright로 이관). "TBD" 없음.
3. **Type consistency**: `SessionUser`는 T3 정의를 T7 api.ts가 import; `validateSession` 반환형(T2)과 일치. `getDb()`(T1)를 T3·T6·T7이 동일 시그니처로 사용. `searchEntities`/`getFixitySummary` 등은 Plan 03 실제 export와 대조(그대로). 쿠키명 상수 COOKIE_NAME 단일 정의.
