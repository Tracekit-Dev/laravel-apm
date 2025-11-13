<?php

// Example Laravel controller with code monitoring
class CheckoutController extends Controller
{
    public function processPayment(Request $request)
    {
        $cart = $request->input('cart');
        $userId = $request->input('user_id');

        // Automatic snapshot capture with label
        tracekit_snapshot('checkout-validation', [
            'user_id' => $userId,
            'cart_items' => count($cart['items'] ?? []),
            'total_amount' => $cart['total'] ?? 0,
            'currency' => $cart['currency'] ?? 'USD',
        ]);

        try {
            // Process payment logic
            $paymentResult = $this->paymentService->charge($cart['total'], $userId);

            // Another checkpoint
            tracekit_snapshot('payment-success', [
                'user_id' => $userId,
                'payment_id' => $paymentResult['id'],
                'amount' => $paymentResult['amount'],
                'status' => 'completed',
            ]);

            return response()->json([
                'success' => true,
                'payment_id' => $paymentResult['id'],
            ]);

        } catch (\Exception $e) {
            // Automatic error capture (configured in service provider)
            tracekit_error_snapshot($e, [
                'user_id' => $userId,
                'cart_total' => $cart['total'] ?? 0,
                'step' => 'payment_processing',
            ]);

            return response()->json([
                'success' => false,
                'error' => 'Payment failed',
            ], 500);
        }
    }

    public function getOrderHistory(Request $request)
    {
        $userId = $request->input('user_id');
        $page = $request->input('page', 1);

        // Debug checkpoint with additional context
        tracekit_debug('order-history-query', [
            'user_id' => $userId,
            'page' => $page,
            'limit' => 20,
        ]);

        $orders = Order::where('user_id', $userId)
            ->with('items')
            ->paginate(20);

        // Capture result summary
        tracekit_snapshot('order-history-loaded', [
            'user_id' => $userId,
            'total_orders' => $orders->total(),
            'returned_orders' => $orders->count(),
            'page' => $page,
        ]);

        return response()->json($orders);
    }
}

// Example service class
class PaymentService
{
    public function charge(float $amount, int $userId): array
    {
        // Validate payment details
        tracekit_snapshot('payment-validation', [
            'amount' => $amount,
            'user_id' => $userId,
            'currency' => 'USD',
        ]);

        // Simulate payment processing
        if ($amount > 1000) {
            throw new \Exception('Amount exceeds limit');
        }

        // Process payment
        $paymentId = 'pay_' . uniqid();

        return [
            'id' => $paymentId,
            'amount' => $amount,
            'status' => 'succeeded',
        ];
    }
}

// Example usage in routes
// routes/web.php or routes/api.php
Route::post('/checkout', [CheckoutController::class, 'processPayment']);
Route::get('/orders', [CheckoutController::class, 'getOrderHistory']);
