<?php

namespace App\Support;

use App\Models\Library;
use Illuminate\Support\Facades\DB;

/**
 * Ready-made SMS and WhatsApp messages.
 * SMS templates are system templates shared by every library (telecom rules need them pre-approved), so libraries only read them.
 * WhatsApp templates are copied into each library when it is onboarded; the library then edits, adds or removes its own.
 * The platform can replace both default lists in Platform Settings. Placeholders: {name} {library} {date} {amount} {plan}.
 */
class MessageTemplates
{
    public const PLACEHOLDERS = ['{name}' => 'student name', '{library}' => 'library name', '{date}' => 'a date', '{amount}' => 'an amount', '{plan}' => 'membership plan', '{due}' => 'amount still due'];

    /** @return list<array{name: string, message: string}> */
    public static function smsDefaults(): array
    {
        return [
            ['name' => 'Joining', 'message' => 'Welcome {name}! Your membership starts on {date}. Wishing you focused study sessions ahead. Regards {library}'],
            ['name' => 'Referral Discount', 'message' => 'Invite friends and get {amount} off on your next renewal. Enjoy special benefits together. Regards {library}'],
            ['name' => 'Festive Wishes', 'message' => 'Warm wishes on {date}! Wishing you and your family joy and happiness. Regards {library}'],
            ['name' => 'Attendance Reminder', 'message' => 'Hi {name}, you have been away for a few days. Stay motivated and get back on track! Regards {library}'],
            ['name' => 'Anniversary', 'message' => 'Happy Anniversary, {name}! Wishing you love, happiness and togetherness in the years ahead. Regards {library}'],
            ['name' => 'Offers', 'message' => 'Hi {name}, we have a special offer on {plan} this week. Visit {library} to know more.'],
            ['name' => 'Holiday', 'message' => 'Dear {name}, we would like to inform you that we will remain closed on {date}. Regards {library}'],
            ['name' => 'Followup', 'message' => 'Hi {name}, just following up on your enquiry with us. We would be happy to assist you further. Regards {library}'],
            ['name' => 'Due Payment', 'message' => 'Dear {name}, a gentle reminder that your payment of {amount} is pending. Kindly pay at your convenience. Regards {library}'],
            ['name' => 'Renewal', 'message' => 'Dear {name}, your {plan} membership is due for renewal on {date}. Kindly renew on time to continue. Regards {library}'],
            ['name' => 'Birthday', 'message' => 'Happy Birthday, {name}! Wishing you happiness, good health and great achievements in the year ahead. Regards {library}'],
            ['name' => 'Renewed', 'message' => 'Dear {name}, your {plan} membership is renewed from {date}. Thank you for staying with us. Regards {library}'],
            ['name' => 'Payment Received', 'message' => 'Dear {name}, we received your payment of {amount}. Amount still due: {due}. Regards {library}'],
        ];
    }

    /** @return list<array{name: string, message: string}> */
    public static function whatsappDefaults(): array
    {
        return [
            ['name' => 'Membership', 'message' => "📚 Congratulations on your membership with {library}!\n\nWe're thrilled to have you on board, {name}, and can't wait to see you explore new worlds through books. 📖✨\n\nPlease collect your membership card and entry pass from the front desk. 📚"],
            ['name' => 'Due Payment Reminder', 'message' => "📚 Gentle Reminder: Membership payment due\n\nHi {name}, this is a kind reminder that your payment of {amount} is due.\n\nKindly clear your dues at the earliest to avoid any interruption in our services."],
            ['name' => 'Renewal', 'message' => "Hi {name}, your {plan} membership is due for renewal on {date}. 📚\nLet's keep the momentum going and stay committed to your reading journey! ✨\n\nRenew now to continue enjoying uninterrupted access to books, resources, and special events. 📖⏳"],
            ['name' => 'Birthday', 'message' => "🎉 Happy Birthday, {name}! 🎉\n\nWishing you a day full of joy, laughter, and wonderful stories. 📚✨\nMay this year bring you new adventures, knowledge, and endless success, both in life and in your reading journey. 📖🌟"],
            ['name' => 'Offers', 'message' => "🎉 Special offer just for you, {name}! 🎉\n\nUnlock amazing benefits on your next library membership plan! 📚✨\nEnjoy exclusive discounts, bonus borrowing privileges, or extended validity when you renew or upgrade this week. 📖🎁"],
            ['name' => 'Holiday', 'message' => "📢 Holiday notice\n\nOur library will remain closed on {date} in observance of the holiday. 📚🎉\n\nTake this time to relax, recharge, and enjoy some wonderful moments with your loved ones. ❤️📖"],
            ['name' => 'Anniversary', 'message' => "🎉 Happy membership anniversary, {name}! 🎉\n\nToday marks another milestone in your reading journey with us, and we couldn't be more proud! 📚✨\nYour passion for learning, exploration, and discovery is truly inspiring. 🌟📖"],
            ['name' => 'Renewed', 'message' => "✅ Membership renewed\n\nHi {name}, your {plan} membership is renewed from {date}. Thank you for continuing your reading journey with {library}! 📚"],
            ['name' => 'Payment Received', 'message' => "💳 Payment received\n\nHi {name}, we received your payment of {amount}. Amount still due: {due}.\nThank you! — {library}"],
            ['name' => 'Inquiry', 'message' => "📚 Thank you for being part of our reading journey, {name}!\n\nYour commitment to learning and personal growth truly inspires us. ✨📖\nTaking the first step toward expanding your knowledge is never easy, but you've done it, and that speaks volumes about your dedication. 🌟"],
        ];
    }

    /** System SMS templates: the platform's edited list, or the built-in one. */
    public static function sms(): array
    {
        return self::clean((array) BillingSettings::get('sms_templates')) ?: self::smsDefaults();
    }

    /** What a newly onboarded library starts with on WhatsApp. */
    public static function whatsappStarter(): array
    {
        return self::clean((array) BillingSettings::get('whatsapp_default_templates')) ?: self::whatsappDefaults();
    }

    /** Give a library its starter WhatsApp templates (once: nothing happens if it already has some). Plain inserts, so it works while the library is still being set up. */
    public static function seedWhatsapp(Library $library): void
    {
        if (DB::table('whatsapp_templates')->where('library_id', $library->id)->exists()) {
            return;
        }

        $now = now();
        DB::table('whatsapp_templates')->insert(array_map(
            fn ($t, $i) => ['library_id' => $library->id, 'name' => $t['name'], 'message' => $t['message'], 'sort' => $i, 'created_at' => $now, 'updated_at' => $now],
            self::whatsappStarter(), array_keys(self::whatsappStarter()),
        ));
    }
    /** @return list<array{name: string, message: string}> only rows that have both a name and a message */
    protected static function clean(array $rows): array
    {
        return array_values(array_filter(array_map(fn ($r) => ['name' => trim((string) ($r['name'] ?? '')), 'message' => trim((string) ($r['message'] ?? ''))], $rows), fn ($r) => $r['name'] !== '' && $r['message'] !== ''));
    }

    /** Fill a template's placeholders. Unknown ones are left as they are. */
    public static function fill(string $message, array $values): string
    {
        return strtr($message, collect($values)->mapWithKeys(fn ($v, $k) => ['{'.trim($k, '{}').'}' => (string) $v])->all());
    }
}
