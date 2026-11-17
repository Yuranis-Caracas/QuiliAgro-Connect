if ('serviceWorker' in navigator) {
    window.addEventListener('load', () => {
        navigator.serviceWorker.register('/quiliagro_connect/service-worker.js')
            .then(registration => {
                console.log('✅ SW registrado:', registration.scope);

                registration.addEventListener('updatefound', () => {
                    const newWorker = registration.installing;
                    newWorker.addEventListener('statechange', () => {
                        if (newWorker.state === 'installed' && navigator.serviceWorker.controller) {
                            mostrarBannerActualizacion(newWorker);
                        }
                    });
                });
            })
            .catch(err => console.error('❌ Error registrando SW:', err));
    });

    let refreshing = false;
    navigator.serviceWorker.addEventListener('controllerchange', () => {
        if (!refreshing) {
            refreshing = true;
            window.location.reload();
        }
    });
}

function mostrarBannerActualizacion(newWorker) {
    if (document.getElementById('pwa-update-banner')) return;

    const banner = document.createElement('div');
    banner.id = 'pwa-update-banner';
    banner.style.cssText = `
        position: fixed; bottom: 20px; left: 50%; transform: translateX(-50%);
        background: #28a745; color: white; padding: 14px 24px;
        border-radius: 12px; z-index: 99999; display: flex;
        align-items: center; gap: 14px; box-shadow: 0 6px 20px rgba(0,0,0,0.25);
        font-size: 14px; font-weight: 600; max-width: 90vw;
    `;
    banner.innerHTML = `
        <i class="bi bi-arrow-repeat" style="font-size:18px;"></i>
        <span>Nueva versión disponible</span>
        <button onclick="aplicarActualizacion()" style="
            background:white; color:#28a745; border:none;
            padding:6px 16px; border-radius:6px; cursor:pointer;
            font-weight:700; font-size:13px;
        ">Actualizar</button>
        <button onclick="this.closest('#pwa-update-banner').remove()" style="
            background:transparent; color:white; border:none;
            cursor:pointer; font-size:18px; line-height:1;
        ">✕</button>
    `;
    document.body.appendChild(banner);
    window._newWorker = newWorker;
}

function aplicarActualizacion() {
    if (window._newWorker) {
        window._newWorker.postMessage('skipWaiting');
    }
}
window.aplicarActualizacion = aplicarActualizacion;