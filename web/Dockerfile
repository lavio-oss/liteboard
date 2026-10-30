FROM node:22-alpine AS deps

WORKDIR /app

RUN corepack enable

COPY package.json pnpm-lock.yaml pnpm-workspace.yaml ./
RUN pnpm install --frozen-lockfile

COPY . .

FROM deps AS build
RUN pnpm build

FROM node:22-alpine AS prod
WORKDIR /app
ENV NODE_ENV=production HOST=127.0.0.1 PORT=3000
COPY --from=build /app/.output ./.output
EXPOSE 3000
CMD ["node", ".output/server/index.mjs"]

# Last stage stays the default target so the dev compose file keeps `pnpm dev`.
FROM deps AS dev
EXPOSE 3000
CMD ["pnpm", "dev", "--host", "0.0.0.0"]
