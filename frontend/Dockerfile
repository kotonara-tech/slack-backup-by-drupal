# frontend(Next.js) のローカル開発用イメージ（docker-compose の frontend サービス）。
FROM node:22-alpine
WORKDIR /app

# 依存だけ先に入れてキャッシュを効かせる
COPY package.json package-lock.json* ./
RUN npm install

# ソースは docker-compose で bind mount（app/ lib/）。残りもコピー。
COPY . .

EXPOSE 3000
CMD ["npm", "run", "dev"]
