FROM node:20-alpine AS frontend

WORKDIR /app
COPY frontend/package.json frontend/package-lock.json* ./
RUN npm ci
COPY frontend/ ./
ENV VITE_API_BASE_URL=
RUN npm run build

FROM nginx:1.27-alpine

COPY docker/nginx/web.prod.conf /etc/nginx/conf.d/default.conf
COPY docker/nginx/php-fastcgi.conf /etc/nginx/php-fastcgi.conf
COPY frontend/spa-cache.nginx.conf /etc/nginx/spa-cache.nginx.conf
COPY --from=frontend /app/dist /var/www/frontend/dist
