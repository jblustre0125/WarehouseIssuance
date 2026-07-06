// app/src/main/java/com/example/app/MainActivity.kt
package com.example.app

import android.annotation.SuppressLint
import android.content.Context
import android.os.Bundle
import android.util.Log
import android.webkit.ConsoleMessage
import android.webkit.CookieManager
import android.webkit.WebChromeClient
import android.webkit.WebResourceRequest
import android.webkit.WebResourceResponse
import android.webkit.WebSettings
import android.webkit.WebView
import android.webkit.WebViewClient
import androidx.activity.ComponentActivity
import androidx.activity.compose.setContent
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.filled.Refresh
import androidx.compose.material3.FloatingActionButton
import androidx.compose.material3.Icon
import androidx.compose.material3.Scaffold
import androidx.compose.runtime.Composable
import androidx.compose.runtime.MutableState
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.ui.Modifier
import androidx.compose.ui.viewinterop.AndroidView
import com.example.app.ui.theme.AppTheme

class MainActivity : ComponentActivity() {

    @SuppressLint("UnusedMaterial3ScaffoldPaddingParameter", "SetJavaScriptEnabled")
    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)

        WebView.setWebContentsDebuggingEnabled(true)

        setContent {
            AppTheme {
                val url = "http://192.168.23.64/WarehouseIssuance/pages/auth/login.php"
                val webViewRef = remember { mutableStateOf<WebView?>(null) }

                Scaffold(
                    modifier = Modifier.fillMaxSize(),
                    floatingActionButton = {
                        FloatingActionButton(
                            onClick = {
                                webViewRef.value?.reload()
                            }
                        ) {
                            Icon(
                                imageVector = Icons.Filled.Refresh,
                                contentDescription = "Refresh"
                            )
                        }
                    }
                ) {
                    Box(modifier = Modifier.fillMaxSize()) {
                        WebPage(
                            url = url,
                            webViewRef = webViewRef,
                            modifier = Modifier.fillMaxSize()
                        )
                    }
                }
            }
        }
    }
}

@Composable
fun WebPage(
    url: String,
    webViewRef: MutableState<WebView?>,
    modifier: Modifier = Modifier
) {
    AndroidView(
        factory = { context: Context ->
            WebView(context).apply {

                settings.javaScriptEnabled = true
                settings.domStorageEnabled = true
                settings.databaseEnabled = true

                settings.cacheMode = WebSettings.LOAD_DEFAULT

                settings.allowContentAccess = true
                settings.allowFileAccess = true

                /*
                 * Do not force desktop mode.
                 * Do not force zoom behavior.
                 * Let your web system CSS render normally.
                 */
                settings.useWideViewPort = true
                settings.loadWithOverviewMode = false

                settings.setSupportZoom(false)
                settings.builtInZoomControls = false
                settings.displayZoomControls = false

                settings.javaScriptCanOpenWindowsAutomatically = true
                settings.setSupportMultipleWindows(false)

                if (android.os.Build.VERSION.SDK_INT >= android.os.Build.VERSION_CODES.LOLLIPOP) {
                    settings.mixedContentMode = WebSettings.MIXED_CONTENT_ALWAYS_ALLOW
                }

                val cookieManager = CookieManager.getInstance()
                cookieManager.setAcceptCookie(true)

                if (android.os.Build.VERSION.SDK_INT >= android.os.Build.VERSION_CODES.LOLLIPOP) {
                    cookieManager.setAcceptThirdPartyCookies(this, true)
                }

                webChromeClient = object : WebChromeClient() {
                    override fun onConsoleMessage(consoleMessage: ConsoleMessage): Boolean {
                        Log.d(
                            "WebViewConsole",
                            "${consoleMessage.message()} -- ${consoleMessage.sourceId()}:${consoleMessage.lineNumber()}"
                        )
                        return true
                    }
                }

                webViewClient = object : WebViewClient() {

                    override fun shouldOverrideUrlLoading(
                        view: WebView?,
                        request: WebResourceRequest?
                    ): Boolean {
                        return false
                    }

                    override fun onReceivedHttpError(
                        view: WebView,
                        request: WebResourceRequest,
                        errorResponse: WebResourceResponse
                    ) {
                        Log.e(
                            "WebViewHttpError",
                            "status=${errorResponse.statusCode} reason=${errorResponse.reasonPhrase} url=${request.url} main=${request.isForMainFrame}"
                        )
                    }

                    override fun onReceivedError(
                        view: WebView,
                        request: WebResourceRequest,
                        error: android.webkit.WebResourceError
                    ) {
                        Log.e(
                            "WebViewError",
                            "code=${error.errorCode} desc=${error.description} url=${request.url} main=${request.isForMainFrame}"
                        )
                    }

                    override fun onPageFinished(view: WebView?, url: String?) {
                        super.onPageFinished(view, url)
                        CookieManager.getInstance().flush()
                        Log.d("WebView", "Finished loading: $url")
                    }
                }

                webViewRef.value = this
                loadUrl(url)
            }
        },
        update = { webView ->
            if (webView.url.isNullOrBlank()) {
                webView.loadUrl(url)
            }
        },
        modifier = modifier
    )
}