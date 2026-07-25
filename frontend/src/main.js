import { createApp } from 'vue'
import './style.css'
import App from './App.vue'

// 데모 모드(VITE_DEMO=1)에서만 네트워크를 가로챈다 — 서버 없이 더미 데이터로 굴린다.
// 동적 import 라 평시 빌드에는 데모 코드가 번들에 들어가지 않는다.
if (import.meta.env.VITE_DEMO === '1') {
  const { installDemoMock } = await import('./demo/mock.js')
  installDemoMock()
}

createApp(App).mount('#app')
