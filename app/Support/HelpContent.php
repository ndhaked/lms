<?php

namespace App\Support;

/** Questions and answers on the Need Help? page, in English and Hindi. Video links per question come from platform settings (help_videos). */
class HelpContent
{
    public const ICONS = ['users', 'user-group', 'user-plus', 'seat', 'building', 'clock', 'ticket', 'badge', 'refresh', 'cash', 'check-badge', 'alert', 'book', 'upload', 'shield', 'cog', 'library', 'tablet', 'chat', 'mail', 'bell', 'receipt', 'home'];

    /** @return list<array{key: string, icon: string, en: array{0: string, 1: string}, hi: array{0: string, 1: string}, video?: string}> */
    public static function defaults(): array
    {
        return [
            ['key' => 'staff', 'icon' => 'user-group',
                'en' => ['How to add staff and choose what they can do?', 'Staff → Add staff → enter name, phone and email → tick one or more roles → Save. They get an email link to choose their own password. What each role may do is set under Roles & Permissions. If someone is locked out, use Reset access on their row to send a new link.'],
                'hi' => ['स्टाफ कैसे जोड़ें और उनकी अनुमतियाँ कैसे तय करें?', 'Staff → Add staff → नाम, फोन और ईमेल भरें → एक या ज़्यादा रोल चुनें → Save। उन्हें ईमेल पर पासवर्ड सेट करने का लिंक मिलता है। हर रोल क्या कर सकता है, यह Roles & Permissions में तय होता है। लॉगिन न हो पाए तो उनकी पंक्ति में Reset access से नया लिंक भेजें।']],
            ['key' => 'halls', 'icon' => 'building',
                'en' => ['How to set up halls and floors?', 'Halls & Floors → Add hall → enter name, capacity, opening and closing time and the floor supervisor → Save. Seats are created inside a hall.'],
                'hi' => ['हॉल और फ्लोर कैसे सेट करें?', 'Halls & Floors → Add hall → नाम, क्षमता, खुलने-बंद होने का समय और फ्लोर सुपरवाइज़र भरें → Save। सीटें हॉल के अंदर बनती हैं।']],
            ['key' => 'seats', 'icon' => 'seat',
                'en' => ['How to create seats and give one to a student?', 'Seats → Generate seats → choose the hall, seat type, prefix, start number and how many → Generate. To give a seat, press Allocate next to it and pick the student. Press Release when they leave. Switch between Seat map and Table views at the top.'],
                'hi' => ['सीटें कैसे बनाएँ और स्टूडेंट को सीट कैसे दें?', 'Seats → Generate seats → हॉल, सीट टाइप, प्रीफिक्स, शुरुआती नंबर और कितनी सीटें चुनें → Generate। सीट देने के लिए उसके पास Allocate दबाएँ और स्टूडेंट चुनें। जाने पर Release दबाएँ। ऊपर Seat map और Table व्यू बदल सकते हैं।']],
            ['key' => 'plans', 'icon' => 'ticket',
                'en' => ['How to create membership plans?', 'Membership Plans → Add plan → enter plan name, duration, price, registration fee, seat type, grace days and the shift start and end → Save. Plans can then be assigned to students.'],
                'hi' => ['मेंबरशिप प्लान कैसे बनाएँ?', 'Membership Plans → Add plan → प्लान का नाम, अवधि, कीमत, रजिस्ट्रेशन फीस, सीट टाइप, ग्रेस डेज़ और शिफ्ट शुरू-खत्म समय भरें → Save। फिर प्लान स्टूडेंट को दिए जा सकते हैं।']],
            ['key' => 'student', 'icon' => 'user-plus',
                'en' => ['How to register a new student?', 'Students → Register student → enter full name and phone (email, address, member type and an emergency contact are optional) → Save. Then open the student and press Assign membership.'],
                'hi' => ['नया स्टूडेंट कैसे रजिस्टर करें?', 'Students → Register student → पूरा नाम और फोन भरें (ईमेल, पता, मेंबर टाइप और आपातकालीन संपर्क वैकल्पिक हैं) → Save। फिर स्टूडेंट खोलकर Assign membership दबाएँ।']],
            ['key' => 'assign', 'icon' => 'badge',
                'en' => ['How to give a student a membership?', 'Open the student and press Assign membership (or go to Memberships → Assign membership) → choose the plan, start date and any discount → Assign. The amount becomes a due until you record the payment.'],
                'hi' => ['स्टूडेंट को मेंबरशिप कैसे दें?', 'स्टूडेंट खोलकर Assign membership दबाएँ (या Memberships → Assign membership) → प्लान, शुरू की तारीख और डिस्काउंट चुनें → Assign। पेमेंट दर्ज होने तक रकम बकाया रहती है।']],
            ['key' => 'renew', 'icon' => 'refresh',
                'en' => ['How to renew a membership?', 'Memberships → find the student → Renew → choose the plan → Save. By default the new period starts after the old one ends. A student can also ask for a renewal from My Library; you approve it on the Memberships page.'],
                'hi' => ['मेंबरशिप रिन्यू कैसे करें?', 'Memberships → स्टूडेंट ढूँढें → Renew → प्लान चुनें → Save। आम तौर पर नई अवधि पुरानी के खत्म होने के बाद शुरू होती है। स्टूडेंट My Library से रिन्यूअल की रिक्वेस्ट भी भेज सकता है; आप उसे Memberships पेज पर अप्रूव करते हैं।']],
            ['key' => 'payment', 'icon' => 'cash',
                'en' => ['How to record a payment or collect outstanding dues?', 'Payments → Record payment → choose what it applies to → enter amount, method, date and reference or UTR → Record payment. Part payments are fine; the balance stays in Outstanding Dues. Each payment has a Receipt, and a mistake can be fixed with Refund or Reverse.'],
                'hi' => ['पेमेंट कैसे दर्ज करें या बकाया कैसे वसूलें?', 'Payments → Record payment → चुनें कि किस पर लागू है → राशि, तरीका, तारीख और रेफरेंस या UTR भरें → Record payment। आंशिक भुगतान चलता है; बाकी रकम Outstanding Dues में रहती है। हर पेमेंट की Receipt मिलती है, और गलती Refund या Reverse से सुधारी जाती है।']],
            ['key' => 'studentpay', 'icon' => 'check-badge',
                'en' => ['A student says they paid. How do I confirm it?', 'Turn on student payment submissions in Settings. Students then use "I have paid" in My Library, and their details appear under Student Payments. Check the money arrived and confirm it, or reject it with a reason.'],
                'hi' => ['स्टूडेंट कहता है उसने पेमेंट कर दिया। उसे कैसे कन्फर्म करूँ?', 'Settings में स्टूडेंट पेमेंट सबमिशन चालू करें। फिर स्टूडेंट My Library में "I have paid" से जानकारी भेजते हैं, जो Student Payments में दिखती है। रकम आई है यह जाँचकर कन्फर्म करें, या कारण लिखकर रिजेक्ट करें।']],
            ['key' => 'attendance', 'icon' => 'clock',
                'en' => ['How to check students in and out?', 'Attendance → Check-in → search by name, phone or student ID → Check in. Only students with an active membership can check in. Press Check out when they leave. If someone forgot to check out, use Close visit, or Correct and give a reason.'],
                'hi' => ['स्टूडेंट का चेक-इन और चेक-आउट कैसे करें?', 'Attendance → Check-in → नाम, फोन या स्टूडेंट ID से खोजें → Check in। केवल सक्रिय मेंबरशिप वाले स्टूडेंट चेक-इन कर सकते हैं। जाते समय Check out दबाएँ। कोई चेक-आउट भूल जाए तो Close visit या कारण देकर Correct करें।']],
            ['key' => 'books', 'icon' => 'book',
                'en' => ['How to add books and print labels?', 'Books → Add book (or Import to upload many from a file) → add the copies. Use Categories to organise titles. Open Labels to print a barcode or QR for every copy, and Stock-take to check the shelves.'],
                'hi' => ['किताबें कैसे जोड़ें और लेबल कैसे प्रिंट करें?', 'Books → Add book (या फाइल से कई एक साथ जोड़ने के लिए Import) → कॉपी जोड़ें। किताबों को व्यवस्थित करने के लिए Categories इस्तेमाल करें। हर कॉपी का बारकोड या QR प्रिंट करने के लिए Labels और शेल्फ जाँचने के लिए Stock-take खोलें।']],
            ['key' => 'issue', 'icon' => 'refresh',
                'en' => ['How to issue and return books?', 'Issue & Return → search the member (or scan their ID card) → scan each book\'s barcode or accession number → Issue. To return, scan the book on the return side and confirm its condition. Late returns add a fine automatically. Renew a loan from Loans.'],
                'hi' => ['किताब इश्यू और रिटर्न कैसे करें?', 'Issue & Return → मेंबर खोजें (या उसका ID कार्ड स्कैन करें) → हर किताब का बारकोड या एक्सेशन नंबर स्कैन करें → Issue। लौटाने के लिए रिटर्न साइड पर किताब स्कैन करके उसकी हालत चुनें। देर होने पर जुर्माना अपने-आप लगता है। लोन Loans से रिन्यू करें।']],
            ['key' => 'fines', 'icon' => 'alert',
                'en' => ['How do fines work, and how to waive one?', 'Fines are charged per late day using the loan rules for the member type (set in Settings → Books & e-books). On the Fines page press Collect to take payment, or Waive and give a reason. Every action is kept in the history.'],
                'hi' => ['जुर्माना कैसे लगता है और माफ कैसे करें?', 'जुर्माना देरी के हर दिन के हिसाब से मेंबर टाइप के लोन नियमों से लगता है (Settings → Books & e-books में तय)। Fines पेज पर Collect से रकम लें, या कारण लिखकर Waive करें। हर कार्रवाई हिस्ट्री में दर्ज रहती है।']],
            ['key' => 'reservations', 'icon' => 'ticket',
                'en' => ['What are reservations?', 'When every copy of a title is out, members can reserve it and wait in a queue. When a copy comes back it is held for the first person for a few days (3 by default). You see waiting and ready reservations on the Reservations page.'],
                'hi' => ['रिज़र्वेशन क्या होते हैं?', 'किसी किताब की सारी कॉपी बाहर हों तो मेंबर उसे रिज़र्व करके कतार में लग सकते हैं। कॉपी लौटने पर वह कुछ दिन (डिफ़ॉल्ट 3) पहले व्यक्ति के लिए रोकी जाती है। प्रतीक्षा और तैयार रिज़र्वेशन Reservations पेज पर दिखते हैं।']],
            ['key' => 'export', 'icon' => 'upload',
                'en' => ['How to export student data and reports?', 'Students → Export CSV or Export Excel. For reports open Reports, pick a report and choose CSV, Excel or PDF. Exports need the Data exports feature in your plan and the permission to export.'],
                'hi' => ['स्टूडेंट डेटा और रिपोर्ट कैसे एक्सपोर्ट करें?', 'Students → Export CSV या Export Excel। रिपोर्ट के लिए Reports खोलें, रिपोर्ट चुनें और CSV, Excel या PDF चुनें। एक्सपोर्ट के लिए आपके प्लान में Data exports और एक्सपोर्ट की अनुमति ज़रूरी है।']],
            ['key' => 'login', 'icon' => 'shield',
                'en' => ['How can a student log in?', 'Add the student\'s email, open their profile and press Create login. They get an email link to set a password, then sign in at the login page. My Library shows their membership, seat, payments and borrowed books.'],
                'hi' => ['स्टूडेंट लॉगिन कैसे करे?', 'स्टूडेंट का ईमेल जोड़ें, उनकी प्रोफ़ाइल खोलकर Create login दबाएँ। उन्हें पासवर्ड सेट करने का ईमेल लिंक मिलता है, फिर वे लॉगिन पेज से साइन-इन करते हैं। My Library में उनकी मेंबरशिप, सीट, पेमेंट और ली गई किताबें दिखती हैं।']],
            ['key' => 'multi', 'icon' => 'library',
                'en' => ['How to switch between libraries?', 'If your login belongs to more than one library, click the library name at the top of the sidebar and pick another. Your menu and permissions follow the library you choose.'],
                'hi' => ['लाइब्रेरी कैसे बदलें?', 'अगर आपका लॉगिन एक से ज़्यादा लाइब्रेरी से जुड़ा है, तो साइडबार के ऊपर लाइब्रेरी के नाम पर क्लिक करके दूसरी चुनें। मेन्यू और अनुमतियाँ चुनी हुई लाइब्रेरी के हिसाब से बदलती हैं।']],
            ['key' => 'password', 'icon' => 'cog',
                'en' => ['How to reset a password?', 'Login page → Forgot password → enter your email → open the link we send → set a new password. For a staff member, an owner can press Reset access on the Staff page to send them a link.'],
                'hi' => ['पासवर्ड रीसेट कैसे करें?', 'Login पेज → Forgot password → ईमेल भरें → भेजे गए लिंक को खोलें → नया पासवर्ड सेट करें। स्टाफ के लिए ओनर Staff पेज पर Reset access दबाकर लिंक भेज सकता है।']],
            ['key' => 'subscription', 'icon' => 'tablet',
                'en' => ['How to buy or renew a subscription plan?', 'My Subscription → View plans and upgrade → Choose Plan → pay as the instructions show → send the reference and proof. Access continues once we approve it. Referral coins can lower the amount.'],
                'hi' => ['सब्सक्रिप्शन प्लान कैसे खरीदें या रिन्यू करें?', 'My Subscription → View plans and upgrade → Choose Plan → बताए अनुसार भुगतान करें → रेफरेंस और प्रूफ भेजें। हमारे अप्रूव करते ही एक्सेस चालू रहता है। रेफरल कॉइन से रकम कम हो सकती है।']],
            ['key' => 'referral', 'icon' => 'users',
                'en' => ['How do I earn referral coins?', 'Refer & Earn → share your link with another library owner. When their first subscription payment is approved you earn coins. Use them when you pay for a plan, up to a share of the price.'],
                'hi' => ['रेफरल कॉइन कैसे कमाऊँ?', 'Refer & Earn → अपना लिंक किसी दूसरे लाइब्रेरी ओनर को भेजें। उनका पहला सब्सक्रिप्शन पेमेंट अप्रूव होते ही आपको कॉइन मिलते हैं। प्लान का भुगतान करते समय कीमत के एक हिस्से तक इन्हें इस्तेमाल करें।']],
        ];
    }

    /** The questions shown on the Need Help? page: the platform's edited list, or the built-in set. */
    public static function faqs(): array
    {
        $stored = (array) BillingSettings::get('help_faqs');
        if ($stored === []) {
            $legacy = (array) BillingSettings::get('help_videos');

            return array_map(fn ($f) => $f + ['video' => (string) ($legacy[$f['key']] ?? '')], self::defaults());
        }

        return array_values(array_map(fn ($f) => [
            'key' => (string) $f['key'], 'icon' => in_array($f['icon'] ?? '', self::ICONS, true) ? $f['icon'] : 'chat',
            'en' => [(string) $f['en'][0], (string) $f['en'][1]],
            'hi' => [(string) (($f['hi'][0] ?? '') ?: $f['en'][0]), (string) (($f['hi'][1] ?? '') ?: $f['en'][1])],
            'video' => (string) ($f['video'] ?? ''),
        ], $stored));
    }

    /** @return array<string, string> question key => video URL (only valid https links) */
    public static function videos(): array
    {
        return collect(self::faqs())->filter(fn ($f) => str_starts_with($f['video'], 'https://'))->pluck('video', 'key')->all();
    }
}
