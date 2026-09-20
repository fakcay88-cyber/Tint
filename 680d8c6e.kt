package nl.tinttotaal.app

import kotlinx.coroutines.delay
import java.time.LocalDate
import java.time.LocalDateTime
import java.time.LocalTime
import java.time.YearMonth

/**
 * Mock-service: zelfde interface als de echte brug, maar met testdata.
 * Zo draait de app volledig op je telefoon zonder WordPress/Mollie/RDW.
 *
 * Testkentekens: 1XKK95 (VW Golf), XX777X (BMW X5), 99ZZZ9 (niet gevonden)
 * Kortingscodes: WELKOMTINT10 (10% korting), WELKOMTINTGRATIS (zonneband gratis)
 */
class MockTintService : TintService {

    private val config by lazy { mockConfig() }
    private val bookings = mutableMapOf<String, BookingDetail>()
    private val bezetteSlots = mutableSetOf<Pair<String, String>>()

    /* ---------- config: realistische voorbeeldprijzen ---------- */
    private fun mockConfig() = BridgeConfig(
        aanbetaling = 50.0,
        modelsoorten = listOf(
            Modelsoort("hatchback3", "Hatchback (3 deurs)", 149.0),
            Modelsoort("hatchback5", "Hatchback (5 deurs)", 169.0),
            Modelsoort("sedan", "Sedan/Coupé", 179.0),
            Modelsoort("station", "Station", 189.0),
            Modelsoort("suv", "SUV/MPV", 199.0),
            Modelsoort("overige", "Overige", 210.0)
        ),
        tint_percentages = listOf("70", "35", "20", "5"),
        tint_labels = mapOf(
            "70" to "Licht 70%",
            "35" to "Medium 35%",
            "20" to "Donker 20%",
            "5" to "Privacy 5% (meest gekozen)"
        ),
        voorruit_opties = listOf("70%", "Sky Blue", "Red Lava", "Sunset Orange", "Forest Green"),
        pakketten = listOf(
            Pakket("a_stijl", "A-stijl", "Voorramen", "Max 70% toegestaan", prijzenMatrix(99.0, 109.0, 119.0)),
            Pakket("b_stijl", "B-stijl", "Achterramen", null, prijzenMatrix(199.0, 219.0, 239.0)),
            Pakket("voorruit", "Voorruit", "Volledige voorruit", "Max 70% toegestaan", prijzenMatrix(149.0, 159.0, 169.0)),
            Pakket("dakraam", "Dakraam", "Glazen dak", null, prijzenMatrix(199.0, 219.0, 249.0))
        ),
        extra_opties = listOf(
            ExtraOptie("xr_ceramic", "XR Ceramic", "Hoog warmtewerende keramische folie", 295.0),
            ExtraOptie("zonneband", "Zonneband", null, 105.0),
            ExtraOptie("koplampen", "Koplampen tinten", null, 235.0),
            ExtraOptie("achterlichten", "Achterlichten tinten", null, 275.0)
        )
    )

    private fun prijzenMatrix(klein: Double, midden: Double, groot: Double) = mapOf(
        "hatchback3" to klein, "hatchback5" to klein,
        "sedan" to midden, "station" to midden,
        "suv" to groot, "overige" to groot
    )

    /* ---------- endpoints ---------- */
    override suspend fun config(): BridgeConfig {
        delay(400)
        return config
    }

    override suspend fun availability(month: String): Availability {
        delay(300)
        val ym = YearMonth.parse(month)
        val now = LocalDateTime.now()
        val days = (1..ym.lengthOfMonth()).map { day ->
            val date = ym.atDay(day)
            val isZondag = date.dayOfWeek == java.time.DayOfWeek.SUNDAY
            val slots = listOf("09:00", "10:30", "12:00", "13:30", "15:00", "16:30").map { t ->
                // deterministisch "bezettingspatroon": ~1 op de 4 slots vol
                val seed = (date.toString() + t).hashCode()
                val bezet = isZondag || (seed % 4 == 0)
                val slotTijd = LocalDateTime.of(date, LocalTime.parse(t))
                Slot(t, !bezet && slotTijd > now && (date.toString() to t) !in bezetteSlots)
            }
            Day(date.toString(), slots)
        }
        return Availability(month, days)
    }

    override suspend fun vehicle(kenteken: String): Vehicle {
        delay(500)
        return when (kenteken.uppercase().replace("-", "").replace(" ", "")) {
            "1XKK95" -> Vehicle(true, "1XKK95", "Volkswagen", "Golf", "personenauto", "Grijs", "2019")
            "XX777X" -> Vehicle(true, "XX777X", "BMW", "X5 xDrive30d", "personenauto", "Zwart", "2022")
            "9TTR33" -> Vehicle(true, "9TTR33", "Tesla", "Model Y Long Range", "personenauto", "Wit", "2023")
            else -> Vehicle(found = false, kenteken = kenteken.uppercase())
        }
    }

    override suspend fun discount(body: DiscountRequest): DiscountResult {
        delay(400)
        val subtotal = subtotal(body.modelsoort, body.pakketten, body.extra_opties)
        return when (body.code.uppercase().trim()) {
            "WELKOMTINT10" -> DiscountResult(
                valid = true, type = "percent",
                kortingsbedrag = kotlin.math.round(subtotal * 0.10 * 100) / 100,
                resterend = subtotal * 0.90,
                message = "Kortingscode toegepast."
            )
            "WELKOMTINTGRATIS" -> {
                if ("zonneband" in body.extra_opties) DiscountResult(
                    valid = true, type = "gratis_zonneband", kortingsbedrag = 105.0,
                    resterend = subtotal - 105.0, message = "Zonneband is gratis toegevoegd."
                ) else DiscountResult(
                    valid = false, message = "Voeg eerst de zonneband toe als extra optie."
                )
            }
            else -> DiscountResult(valid = false, message = "Ongeldige of niet-geldige kortingscode.")
        }
    }

    override suspend fun createBooking(body: BookingRequest): BookingResult {
        delay(600)
        val slot = body.datum to body.tijd
        if (slot in bezetteSlots) {
            throw Exception("Dit tijdslot is net bezet geraakt. Kies een ander moment.")
        }
        bezetteSlots += slot

        val subtotal = subtotal(body.modelsoort, body.pakketten, body.extra_opties)
        val korting = when (body.korting_code?.uppercase()?.trim()) {
            "WELKOMTINT10" -> kotlin.math.round(subtotal * 0.10 * 100) / 100
            "WELKOMTINTGRATIS" -> if ("zonneband" in body.extra_opties) 105.0 else 0.0
            else -> 0.0
        }

        val ref = "TT" + java.text.SimpleDateFormat("yyMMdd").format(java.util.Date()) +
                "-" + (1000..9999).random()

        bookings[ref] = BookingDetail(
            booking_ref = ref,
            status = "awaiting_payment",
            datum = body.datum, tijd = body.tijd,
            voornaam = body.voornaam, achternaam = body.achternaam, email = body.email,
            kenteken = body.kenteken, automodel = body.automodel,
            modelsoort = body.modelsoort,
            pakketten = body.pakketten.joinToString(","),
            extra_opties = body.extra_opties.joinToString(","),
            totaalbedrag = "%.2f".format(subtotal),
            korting = "%.2f".format(korting),
            aanbetaling = "50.00"
        )

        return BookingResult(
            booking_ref = ref,
            totaalbedrag = subtotal,
            korting = korting,
            aanbetaling = 50.0,
            resterend = (subtotal - korting - 50.0).coerceAtLeast(0.0)
        )
    }

    override suspend fun booking(ref: String): BookingDetail {
        delay(300)
        return bookings[ref] ?: throw Exception("Boeking niet gevonden")
    }

    override suspend fun payment(ref: String): PaymentUrl {
        delay(800)
        // Mock: geen echte Mollie-checkout. checkout_url = null,
        // dus de app springt direct naar het bevestigingsscherm.
        bookings[ref] = bookings[ref]?.copy(status = "confirmed")
            ?: throw Exception("Boeking niet gevonden")
        return PaymentUrl(payment_id = "tr_mock_$ref", checkout_url = null, status = "paid")
    }

    /* ---------- helper ---------- */
    private fun subtotal(modelsoort: String, pakketten: List<String>, extra: List<String>): Double {
        var t = 0.0
        pakketten.forEach { key -> config.pakketten.find { it.key == key }?.let { t += it.prijzen[modelsoort] ?: 0.0 } }
        extra.forEach { key -> config.extra_opties.find { it.key == key }?.let { t += it.prijs } }
        return t
    }
}
