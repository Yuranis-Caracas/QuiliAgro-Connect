const CACHE_NAME = 'quiliagro-v6';
const RUNTIME_CACHE = 'quiliagro-runtime-v6';

// Recursos para cachear
const urlsToCache = [
  '/quiliagro_connect/vistas/auth/login.php',
  '/quiliagro_connect/vistas/admin/panel.php',
  '/quiliagro_connect/css/styles.css',
  '/quiliagro_connect/imagenes/logo.png',
  '/quiliagro_connect/imagenes/icon-192x192.png',
  '/quiliagro_connect/imagenes/icon-512x512.png',
  '/quiliagro_connect/manifest.json',
  'https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css'
];

self.addEventListener('install', (event) => {
  console.log('✅ SW v6: Instalando...');
  
  event.waitUntil(
    caches.open(CACHE_NAME)
      .then((cache) => {
        console.log('📦 SW v6: Cacheando recursos');
        return Promise.allSettled(
          urlsToCache.map((url) => 
            cache.add(url).catch((err) => {
              console.warn(`⚠️ No se cacheo ${url}:`, err.message);
              return Promise.resolve();
            })
          )
        );
      })
      .then(() => {
        console.log('✅ SW v6: Instalación completa');
        return self.skipWaiting();
      })
  );
});

self.addEventListener('activate', (event) => {
  console.log('✅ SW v6: Activando...');
  
  event.waitUntil(
    Promise.all([
      caches.keys().then((cacheNames) => {
        return Promise.all(
          cacheNames.map((cacheName) => {
            if (cacheName !== CACHE_NAME && cacheName !== RUNTIME_CACHE) {
              console.log('🧹 SW v6: Borrando cache viejo:', cacheName);
              return caches.delete(cacheName);
            }
          })
        );
      }),
      self.clients.claim()
    ]).then(() => {
      console.log('✅ SW v6: Activado y en control');
    })
  );
});

self.addEventListener('fetch', (event) => {
  const { request } = event;
  const url = new URL(request.url);
  
  if (request.method !== 'GET') return;
  
  if (!url.protocol.startsWith('http')) return;
  
  if (url.hostname.includes('ngrok') && url.pathname === '/') return;
  
  const esOrigenPropio = url.origin === location.origin;
  const esRecursoExterno = 
    url.href.includes('cdn.jsdelivr.net') || 
    url.href.includes('cdnjs.cloudflare.com') ||
    url.href.includes('fonts.googleapis.com');
  
  if (!esOrigenPropio && !esRecursoExterno) return;
  
  if (url.pathname.endsWith('.php')) {
    event.respondWith(networkFirstStrategy(request, url));
    return;
  }
  
  if (isStaticAsset(url.pathname) || esRecursoExterno) {
    event.respondWith(cacheFirstStrategy(request));
    return;
  }
  
  event.respondWith(networkFirstStrategy(request, url));
});

function isStaticAsset(pathname) {
  const staticExtensions = ['.png', '.jpg', '.jpeg', '.gif', '.svg', 
                           '.css', '.js', '.json', '.ico', '.woff', 
                           '.woff2', '.ttf', '.eot'];
  return staticExtensions.some(ext => pathname.endsWith(ext));
}

async function networkFirstStrategy(request, url) {
  try {
    const networkResponse = await fetch(request);
    
    if (networkResponse.ok && networkResponse.status !== 206) {
  const cache = await caches.open(RUNTIME_CACHE);
  cache.put(request, networkResponse.clone());
  }
    
    return networkResponse;
  } catch (error) {
    console.log('📡 Sin red, usando cache:', request.url);
    
    const cachedResponse = await caches.match(request);
    
    if (cachedResponse) {
      return cachedResponse;
    }
    
    if (url.pathname.includes('/admin/')) {
      return new Response(
        createAdminOfflinePage(),
        {
          headers: { 
            'Content-Type': 'text/html; charset=UTF-8',
            'Cache-Control': 'no-store'
          }
        }
      );
    }
    
    return new Response(
      createOfflinePage(),
      {
        headers: { 
          'Content-Type': 'text/html; charset=UTF-8',
          'Cache-Control': 'no-store'
        }
      }
    );
  }
}

async function cacheFirstStrategy(request) {
  const cachedResponse = await caches.match(request);
  
  if (cachedResponse) {
    fetch(request).then((networkResponse) => {
      if (networkResponse.ok) {
        caches.open(RUNTIME_CACHE).then((cache) => {
          cache.put(request, networkResponse);
        });
      }
    }).catch(() => {});
    
    return cachedResponse;
  }
  
  try {
    const networkResponse = await fetch(request);
    
    if (networkResponse.ok && networkResponse.status !== 206) {
      const cache = await caches.open(RUNTIME_CACHE);
      cache.put(request, networkResponse.clone());
    }
    
    return networkResponse;
  } catch (error) {
    if (isImage(request.url)) {
      return new Response(
        createPlaceholderSVG(),
        { headers: { 'Content-Type': 'image/svg+xml' } }
      );
    }
    
    throw error;
  }
}

function isImage(url) {
  return /\.(png|jpg|jpeg|gif|svg|webp)$/i.test(url);
}

// Página offline genérica
function createOfflinePage() {
  return `
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sin conexión - QuiliAgro Connect</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: linear-gradient(135deg, #e4e5e9 0%, #edebf0 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }
        .offline-container {
            background: white;
            border-radius: 15px;
            box-shadow: 0 15px 35px rgba(0, 0, 0, 0.2);
            padding: 40px;
            text-align: center;
            max-width: 400px;
        }
        .offline-icon {
            font-size: 80px;
            margin-bottom: 20px;
        }
        h1 {
            color: #333;
            margin-bottom: 15px;
            font-size: 24px;
        }
        p {
            color: #666;
            margin-bottom: 25px;
            line-height: 1.6;
        }
        .btn-retry {
            background: linear-gradient(135deg, #28a745 0%, #20c997 100%);
            color: white;
            border: none;
            padding: 12px 30px;
            border-radius: 8px;
            font-size: 16px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s;
        }
        .btn-retry:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(40, 167, 69, 0.4);
        }
    </style>
</head>
<body>
    <div class="offline-container">
        <div class="offline-icon">📡</div>
        <h1>Sin conexión a internet</h1>
        <p>No pudimos cargar esta página. Verifica tu conexión e intenta nuevamente.</p>
        <button class="btn-retry" onclick="window.location.reload()">
            Reintentar
        </button>
    </div>
</body>
</html>`;
}

// Página offline específica para admin
function createAdminOfflinePage() {
  return `
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Panel Admin - Sin conexión</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: #f0f8f4;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }
        .offline-container {
            background: white;
            border-radius: 15px;
            box-shadow: 0 15px 35px rgba(40, 167, 69, 0.2);
            padding: 40px;
            text-align: center;
            max-width: 500px;
        }
        .offline-icon {
            font-size: 80px;
            margin-bottom: 20px;
        }
        h1 {
            color: #28a745;
            margin-bottom: 15px;
            font-size: 24px;
        }
        p {
            color: #666;
            margin-bottom: 25px;
            line-height: 1.6;
        }
        .info-box {
            background: #fff3cd;
            border-left: 4px solid #ffc107;
            padding: 15px;
            margin: 20px 0;
            border-radius: 8px;
            text-align: left;
        }
        .btn-retry {
            background: linear-gradient(135deg, #28a745 0%, #20c997 100%);
            color: white;
            border: none;
            padding: 12px 30px;
            border-radius: 8px;
            font-size: 16px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s;
            margin-top: 10px;
        }
        .btn-retry:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(40, 167, 69, 0.4);
        }
    </style>
</head>
<body>
    <div class="offline-container">
        <div class="offline-icon">🔒</div>
        <h1>Panel de administración sin conexión</h1>
        <p>No tienes conexión a internet. El panel de administración requiere conexión para funcionar correctamente.</p>
        
        <div class="info-box">
            <strong>⚠️ Funciones limitadas</strong><br>
            Sin conexión no puedes acceder a datos en tiempo real ni realizar cambios en el sistema.
        </div>
        
        <button class="btn-retry" onclick="window.location.reload()">
            Reintentar conexión
        </button>
    </div>
</body>
</html>`;
}

function createPlaceholderSVG() {
  return `<svg width="200" height="200" xmlns="http://www.w3.org/2000/svg">
    <rect width="200" height="200" fill="#f0f0f0"/>
    <text x="100" y="100" font-family="Arial" font-size="14" 
          text-anchor="middle" fill="#999" dy=".3em">
      Imagen no disponible
    </text>
  </svg>`;
}

self.addEventListener('message', (event) => {
  if (event.data === 'skipWaiting') {
    self.skipWaiting();
  }
  
  if (event.data === 'clearCache') {
    event.waitUntil(
      caches.keys().then((cacheNames) => {
        return Promise.all(
          cacheNames.map((cacheName) => caches.delete(cacheName))
        );
      })
    );
  }
});

console.log('🚀 Service Worker QuiliAgro v6 cargado');