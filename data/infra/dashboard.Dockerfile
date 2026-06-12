# ==============================================================================
# Dhiarlink Dashboard — shlink-web-client
# ==============================================================================
# Builds the shlink-web-client React app and serves it via nginx.
# This is a multi-stage build:
#   Stage 1: Fetch the latest shlink-web-client release
#   Stage 2: Serve the static files with nginx
# ==============================================================================

# --- Stage 1: Download and prepare shlink-web-client -------------------------
FROM alpine:3.22 AS builder

ARG SHLINK_WEB_CLIENT_VERSION=latest

RUN apk add --no-cache curl jq unzip

# If SHLINK_WEB_CLIENT_VERSION is "latest", fetch the latest release tag from GitHub
WORKDIR /build
RUN if [ "$SHLINK_WEB_CLIENT_VERSION" = "latest" ]; then \
        VERSION=$(curl -sL https://api.github.com/repos/shlinkio/shlink-web-client/releases/latest | jq -r '.tag_name'); \
    else \
        VERSION=$SHLINK_WEB_CLIENT_VERSION; \
    fi && \
    echo "Downloading shlink-web-client $VERSION" && \
    curl -sL "https://github.com/shlinkio/shlink-web-client/releases/download/${VERSION}/shlink-web-client_${VERSION}_dist.zip" -o release.zip && \
    unzip -q release.zip -d /usr/share/nginx/html && \
    rm release.zip

# --- Stage 2: Serve with nginx ------------------------------------------------
FROM nginx:1.27-alpine

# Copy the built SPA files
COPY --from=builder /usr/share/nginx/html /usr/share/nginx/html

# Copy custom nginx config for SPA routing
COPY data/infra/spa-nginx.conf /etc/nginx/conf.d/default.conf

EXPOSE 80

CMD ["nginx", "-g", "daemon off;"]
