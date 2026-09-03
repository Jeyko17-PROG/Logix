import React from 'react';
import { StyleSheet, SafeAreaView, StatusBar } from 'react-native';
import { WebView } from 'react-native-webview';

export default function App() {
  // __DEV__ es true en Expo Go / `expo start` y false en un build de
  // release (EAS Build): así la MISMA app apunta a tu PC en desarrollo
  // (VirtualHost de Laragon -> backend/public) y al sitio real una vez
  // compilada para entregar a clientes. No hardcodear la IP local acá:
  // esa IP no existe para nadie fuera de tu red.
  const targetUrl = __DEV__ ? 'http://192.168.10.139/' : 'https://fenixpos.lat/';

  return (
    <SafeAreaView style={styles.container}>
      <StatusBar barStyle="dark-content" backgroundColor="#ffffff" />
      <WebView 
        source={{ uri: targetUrl }} 
        style={styles.webview}
        javaScriptEnabled={true}
        domStorageEnabled={true}
        originWhitelist={['*']}
      />
    </SafeAreaView>
  );
}

const styles = StyleSheet.create({
  container: {
    flex: 1,
    backgroundColor: '#ffffff',
  },
  webview: {
    flex: 1,
  },
});