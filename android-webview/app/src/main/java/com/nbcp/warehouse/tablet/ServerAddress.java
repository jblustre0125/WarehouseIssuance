package com.nbcp.warehouse.tablet;

import java.net.URI;
import java.util.Locale;

/** The configured app URL is also the native bridge's trust boundary. */
public final class ServerAddress {
    public final String baseUrl;
    public final String origin;
    private final URI base;

    public ServerAddress(String value) {
        try {
            URI input = new URI(value.trim()).normalize();
            String scheme = input.getScheme() == null ? "" : input.getScheme().toLowerCase(Locale.ROOT);
            if ((!scheme.equals("https") && !scheme.equals("http")) || input.getHost() == null
                    || input.getUserInfo() != null || input.getQuery() != null || input.getFragment() != null
                    || input.getPort() > 65535 || input.getPort() == 0) {
                throw new IllegalArgumentException();
            }
            String host = input.getHost().toLowerCase(Locale.ROOT);
            if (host.equals("localhost") || host.equals("127.0.0.1") || host.equals("[::1]")) {
                throw new IllegalArgumentException("Use the server's network address, not localhost.");
            }
            String path = input.getPath();
            if (path == null || path.isEmpty()) path = "/";
            path = new URI(null, null, path, null).normalize().getPath();
            if (!path.endsWith("/")) path += "/";
            base = new URI(scheme, null, host, input.getPort(), path, null, null);
            baseUrl = base.toASCIIString();
            origin = new URI(scheme, null, host, input.getPort(), null, null, null).toASCIIString();
        } catch (IllegalArgumentException error) {
            throw error;
        } catch (Exception error) {
            throw new IllegalArgumentException("Enter the warehouse app's full http:// or https:// address.");
        }
    }

    public boolean allows(String value) {
        try {
            URI candidate = new URI(value).normalize();
            int basePort = base.getPort() == -1 ? (base.getScheme().equals("https") ? 443 : 80) : base.getPort();
            int candidatePort = candidate.getPort() == -1 ? ("https".equalsIgnoreCase(candidate.getScheme()) ? 443 : 80) : candidate.getPort();
            String path = candidate.getPath() == null ? "" : new URI(null, null, candidate.getPath(), null).normalize().getPath();
            return base.getScheme().equalsIgnoreCase(candidate.getScheme())
                    && base.getHost().equalsIgnoreCase(candidate.getHost())
                    && basePort == candidatePort && candidate.getUserInfo() == null
                    && path.startsWith(base.getPath());
        } catch (Exception ignored) {
            return false;
        }
    }
}
