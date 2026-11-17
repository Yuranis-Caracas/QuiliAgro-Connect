if ('serviceWorker' in navigator) {
  window.addEventListener('load', () => {
    navigator.serviceWorker.register('/quiliagro_connect/service-worker.js')
      .then(registration => {
        console.log('ServiceWorker registrado con éxito:', registration.scope);
      })
      .catch(error => {
        console.log('Error al registrar ServiceWorker:', error);
      });
  });
}

function isPWAInstalled() {
  return window.matchMedia('(display-mode: standalone)').matches || 
         window.navigator.standalone;
}

window.addEventListener('online', () => {
  console.log('Volvió la conexión a internet');
});

window.addEventListener('offline', () => {
  console.log('Sin conexión a internet');
});

window.addEventListener('load', () => {
  if (!navigator.onLine) {
    console.log('Sin conexión a internet');
  }

  if (isPWAInstalled()) {
    console.log('La app está instalada como PWA');
  }
});

window.PWA = {
  isInstalled: isPWAInstalled
};