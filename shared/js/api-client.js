/* Shared same-origin JSON client. Resolve the app root from this asset's URL,
   so /gymproject/, a virtual host, and domain-root deployments all work. */
(() => {
  const currentScript = document.currentScript;
  const scriptUrl = currentScript?.src
    ? new URL(currentScript.src, window.location.href)
    : new URL("shared/js/api-client.js", window.location.href);
  const appBaseUrl = new URL("../../", scriptUrl);

  function resolveEndpoint(endpoint) {
    const normalized = endpoint instanceof URL
      ? endpoint.href
      : String(endpoint || "").replace(/^\/+/, "");
    const url = new URL(normalized, appBaseUrl);
    if (url.origin !== appBaseUrl.origin) {
      throw new TypeError("API requests must use the application's same origin.");
    }
    return url;
  }

  function diagnosticHeaders(headers) {
    const safeHeaders = {};
    headers.forEach((value, key) => {
      safeHeaders[key] = /authorization|csrf|cookie/i.test(key) ? "[redacted]" : value;
    });
    return safeHeaders;
  }

  function validateEnvelope(result, response, url) {
    const validShape = result !== null
      && typeof result === "object"
      && !Array.isArray(result)
      && ["success", "error"].includes(result.status)
      && typeof result.message === "string"
      && Object.prototype.hasOwnProperty.call(result, "data")
      && typeof result.success === "boolean"
      && result.success === (result.status === "success");

    if (!validShape) {
      const error = new Error(
        `The API response contract is invalid (HTTP ${response.status}) from ${url.href}.`,
      );
      error.status = response.status;
      throw error;
    }
    return result;
  }

  const ApiClient = {
    getBaseUrl() {
      return new URL(appBaseUrl.href);
    },

    getApiUrl(endpoint) {
      return resolveEndpoint(endpoint).href;
    },

    async request(endpoint, options = {}) {
      const { redirectOn401 = true, ...requestOptions } = options;
      const url = resolveEndpoint(endpoint);
      const headers = new Headers(requestOptions.headers || {});
      headers.set("Accept", "application/json");
      if (requestOptions.body != null && !(requestOptions.body instanceof FormData)) {
        headers.set("Content-Type", headers.get("Content-Type") || "application/json");
      }
      const method = (requestOptions.method || "GET").toUpperCase();

      let response;
      try {
        response = await fetch(url.href, {
          ...requestOptions,
          headers,
          credentials: "same-origin",
        });
      } catch (cause) {
        const error = new Error(
          `Could not reach the API at ${url.href}. Check the server route and your connection.`,
          { cause },
        );
        error.url = url.href;
        error.method = method;
        throw error;
      }

      const raw = await response.text();
      let result = null;
      let parseError = null;
      try {
        result = raw ? JSON.parse(raw) : null;
      } catch (error) {
        parseError = error;
      }

      if (response.status !== 200) {
        const diagnostic = {
          url: url.href,
          method,
          status: response.status,
          headers: diagnosticHeaders(headers),
          responseContentType: response.headers.get("Content-Type"),
          responseBody: raw.slice(0, 1000),
        };
        if (response.ok) console.info("Gym API returned a non-200 status", diagnostic);
        else console.error("Gym API request failed", diagnostic);
      }

      if (response.status === 404) {
        const error = new Error(`API endpoint not found (HTTP 404): ${url.href}`);
        error.status = 404;
        error.url = url.href;
        error.method = method;
        throw error;
      }

      if (parseError) {
        const responseContentType = response.headers.get("Content-Type") || "unknown content type";
        const staticPhpResponse = response.ok
          && /\.php$/i.test(url.pathname)
          && (window.location.port === "5500" || raw.trimStart().startsWith("<?php"));
        if (!response.ok || staticPhpResponse) {
          console.error("Gym API returned a non-JSON response", {
            url: url.href,
            method,
            status: response.status,
            headers: diagnosticHeaders(headers),
            responseContentType,
            responseBody: raw.slice(0, 300),
          });
        }
        const detail = response.status === 401
          ? "Sign-in was rejected, but the server did not return the required JSON response."
          : staticPhpResponse
            ? "This server is not executing PHP. VS Code Live Server (port 5500) serves PHP files as static content. Open the project through XAMPP Apache, for example http://localhost/gymproject/."
            : `The server returned a non-JSON response (HTTP ${response.status}, ${responseContentType}) from ${url.href}.`;
        const error = new Error(detail, { cause: parseError });
        error.status = response.status;
        error.url = url.href;
        error.method = method;
        throw error;
      }

      result = validateEnvelope(result, response, url);
      if (!response.ok || result.status !== "success") {
        if (response.status === 401 && redirectOn401) {
          const loginUrl = new URL("login.html", appBaseUrl);
          if (window.location.href !== loginUrl.href) window.navigateWithLoader(loginUrl.href);
        }
        const error = new Error(
          result.message || (response.status === 401 ? "Invalid email or password." : `Request failed (HTTP ${response.status}).`),
        );
        error.status = response.status;
        error.data = result.data || result.errors || null;
        error.url = url.href;
        error.method = method;
        throw error;
      }
      return result;
    },

    get(endpoint, options = {}) {
      return this.request(endpoint, { ...options, method: "GET" });
    },

    post(endpoint, payload, options = {}) {
      const headers = new Headers(options.headers || {});
      const body = payload instanceof FormData ? payload : JSON.stringify(payload || {});
      if (!(payload instanceof FormData)) headers.set("Content-Type", "application/json");
      return this.request(endpoint, { ...options, method: "POST", headers, body });
    },
  };

  window.ApiClient = ApiClient;
  // Keep existing portal modules compatible while they migrate to ApiClient methods.
  window.apiClient = (endpoint, options = {}) => ApiClient.request(endpoint, options);
})();
