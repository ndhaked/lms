import './bootstrap';

// Razorpay Checkout: the server creates the order and sends the options; this only opens the window and returns what Razorpay answers.
window.addEventListener('razorpay-open', (event) => {
    const { options, wireId } = event.detail;
    const load = () => new Promise((resolve, reject) => {
        if (window.Razorpay) return resolve();
        const s = document.createElement('script');
        s.src = 'https://checkout.razorpay.com/v1/checkout.js';
        s.onload = resolve;
        s.onerror = () => reject(new Error('Could not load Razorpay'));
        document.head.appendChild(s);
    });
    const wire = window.Livewire.find(wireId);

    load().then(() => {
        const rzp = new window.Razorpay({
            ...options,
            handler: (r) => wire.call('verifyOnline', r.razorpay_payment_id, r.razorpay_order_id, r.razorpay_signature),
            modal: { ondismiss: () => wire.call('onlineDismissed') },
        });
        rzp.on('payment.failed', (r) => wire.call('onlineFailed', (r.error && r.error.description) || 'Payment failed'));
        rzp.open();
    }).catch(() => wire.call('onlineFailed', 'Could not open the payment window. Check your connection and try again.'));
});
