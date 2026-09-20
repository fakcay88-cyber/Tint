package nl.tinttotaal.app

import kotlinx.serialization.Serializable
import okhttp3.MediaType.Companion.toMediaType
import okhttp3.OkHttpClient
import retrofit2.Retrofit
import retrofit2.converter.kotlinxserialization.asConverterFactory
import retrofit2.http.Body
import retrofit2.http.GET
import retrofit2.http.POST
import retrofit2.http.Path
import retrofit2.http.Query

/* ---------- DTO's: 1-op-1 met de bridge-plugin ---------- */

@Serializable
data class Modelsoort(val key: String, val label: String, val base: Double = 0.0)

@Serializable
data class Pakket(
    val key: String,
    val label: String,
    val hint: String? = null,
    val note: String? = null,
    val prijzen: Map<String, Double> = emptyMap()
)

@Serializable
data class ExtraOptie(
    val key: String,
    val label: String,
    val omschrijving: String? = null,
    val prijs: Double = 0.0
)

@Serializable
data class BridgeConfig(
    val aanbetaling: Double,
    val modelsoorten: List<Modelsoort>,
    val tint_percentages: List<String> = emptyList(),
    val tint_labels: Map<String, String> = emptyMap(),
    val voorruit_opties: List<String> = emptyList(),
    val pakketten: List<Pakket> = emptyList(),
    val extra_opties: List<ExtraOptie> = emptyList()
)

@Serializable
data class Slot(val time: String, val available: Boolean)

@Serializable
data class Day(val date: String, val slots: List<Slot>)

@Serializable
data class Availability(val month: String, val days: List<Day>)

@Serializable
data class Vehicle(
    val found: Boolean = false,
    val kenteken: String? = null,
    val merk: String? = null,
    val model: String? = null,
    val type_voertuig: String? = null,
    val kleur: String? = null,
    val bouwjaar: String? = null
)

@Serializable
data class DiscountRequest(
    val code: String,
    val modelsoort: String,
    val pakketten: List<String>,
    val extra_opties: List<String>
)

@Serializable
data class DiscountResult(
    val valid: Boolean,
    val type: String? = null,
    val kortingsbedrag: Double = 0.0,
    val resterend: Double = 0.0,
    val message: String? = null
)

@Serializable
data class BookingRequest(
    val source: String = "app",
    val modelsoort: String,
    val voorruit_optie: String? = null,
    val tint_percentage: String? = null,
    val pakketten: List<String>,
    val extra_opties: List<String>,
    val korting_code: String? = null,
    val datum: String,
    val tijd: String,
    val voornaam: String,
    val achternaam: String,
    val email: String,
    val telefoon: String,
    val kenteken: String? = null,
    val merk: String? = null,
    val automodel: String? = null,
    val bouwjaar: String? = null,
    val kleur: String? = null,
    val opmerkingen: String? = null
)

@Serializable
data class BookingResult(
    val booking_ref: String,
    val totaalbedrag: Double,
    val korting: Double,
    val aanbetaling: Double,
    val resterend: Double
)

@Serializable
data class PaymentUrl(val payment_id: String? = null, val checkout_url: String? = null, val status: String? = null)

@Serializable
data class BookingDetail(
    val booking_ref: String? = null,
    val status: String? = null,
    val datum: String? = null,
    val tijd: String? = null,
    val voornaam: String? = null,
    val achternaam: String? = null,
    val email: String? = null,
    val kenteken: String? = null,
    val automodel: String? = null,
    val modelsoort: String? = null,
    val pakketten: String? = null,
    val extra_opties: String? = null,
    val totaalbedrag: String? = null,
    val korting: String? = null,
    val aanbetaling: String? = null
)

/* ---------- Retrofit-service ---------- */

interface TintService {
    @GET("config") suspend fun config(): BridgeConfig
    @GET("availability") suspend fun availability(@Query("month") month: String): Availability
    @GET("vehicle/{kenteken}") suspend fun vehicle(@Path("kenteken") kenteken: String): Vehicle
    @POST("discount") suspend fun discount(@Body body: DiscountRequest): DiscountResult
    @POST("booking") suspend fun createBooking(@Body body: BookingRequest): BookingResult
    @GET("booking/{ref}") suspend fun booking(@Path("ref") ref: String): BookingDetail
    @POST("payment/{ref}") suspend fun payment(@Path("ref") ref: String): PaymentUrl
}

object Api {
    // Pas aan naar je eigen domein; test eventueel met een lokale omgeving
    private const val BASE_URL = "https://tinttotaal.nl/wp-json/tinttotaal/v1/"

    // Vind je in WordPress: wp_options -> tt_bridge_api_key
    // (of via wp-config.php: define('TT_BRIDGE_API_KEY', '...'))
    private const val API_KEY = "VUL_HIER_JE_API_KEY_IN"

    val service: TintService by lazy {
        val json = kotlinx.serialization.json.Json { ignoreUnknownKeys = true }
        Retrofit.Builder()
            .baseUrl(BASE_URL)
            .client(
                OkHttpClient.Builder()
                    .addInterceptor {
                        it.proceed(
                            it.request().newBuilder()
                                .header("X-TT-API-Key", API_KEY)
                                .build()
                        )
                    }
                    .build()
            )
            .addConverterFactory(json.asConverterFactory("application/json".toMediaType()))
            .build()
            .create(TintService::class.java)
    }
}
