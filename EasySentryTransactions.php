<?php

// A simplified interface for Sentry Transaction tracing.
// 2022 - https://github.com/morgverd/simplified-sentry-transactions

class EasySentryTransaction {
    public bool $autoFinishOnShutdown = true;
    function __construct(\Sentry\Tracing\Transaction $_t) {
        $this->_t = $_t;
    }

    // Start a span under the current Transaction with the provided parameters. Then
    // set the created span as the current span in the Sentry Hub.
    function createSpan(string $operation, ?string $description = null, bool $setSpan = false): \Sentry\Tracing\Span {

        // Create the base span context with provided parameters. If there
        // is a provided description for the span then we should also apply that.
        $context = new \Sentry\Tracing\SpanContext();
        $context->setOp($operation);
        if (!is_null($description)) {
            $context->setDescription($description);
        }

        // Start the span with the transaction parent and then set
        // it as the current span for the Sentry hub if the parameter
        // is enabled.
        $span = $this->_t->startChild($context);
        if ($setSpan) {
            \Sentry\SentrySdk::getCurrentHub()->setSpan($span);
        }

        return $span;
    }

    // End the provided Transaction span and set the current active span for the
    // hub back to the Transaction parent.
    function finishSpan(\Sentry\Tracing\Span $span): void {

        // Finish the provided span and then set the current span for the
        // Sentry hub back to the current transaction parent.
        $span->finish();
        \Sentry\SentrySdk::getCurrentHub()->setSpan($this->_t);
    }

    // Finish the transaction, submitting the transaction and its spans to Sentry.
    function finish(): void {

        // If the current transaction is this transaction, then we should reset it.
        if (SentryPreformance::$currentTransaction === $this->_t) {
            SentryPreformance::$currentTransaction = null;
        }

        // Finally, finish the transaction.
        $this->_t->finish();
    }

    // Shortcut to the measureWrapper function, Applied directly to this parent Transaction.
    function measure(string $name, string $operation, callable $fn, array $parameters): mixed {
        return SentryPreformance::measureWrapper(
            $name,
            $operation,
            $fn,
            $parameters,
            $this
        );
    }
}

class SentryPreformance {

    // This allows for a function to be directly wrapped inside of a measurement call. If Sentry is not being
    // used then the call will execute as normal without any measurement applied ontop of it. The value returned
    // is the same value that is returned from the callable. Example usage:
    /*

        SomethingReallyIntensive::createFourHundredDatabases(
            "localhost:9000"
        )

        The function call above would simply become:

            SentryPreformance::measureWrapper("Fetch x from API", "http.request", "SomethingReallyIntensive::createFourHundredDatabases", [
                "localhost:9000"
            ]);

    */
    // Note:
    //  An EasySentryTransaction instance can be provided to measure within the transaction. In this case, instead of a new
    //  transaction being created the measurement is put inside a span for the given Transaction. 
    public static function measureWrapper(string $name, string $operation, callable $fn, array $parameters, ?EasySentryTransaction $transaction = null): mixed {

        // If there is already a transaction provided then we should use that. If there isn't one then we must create a 
        // new transaction to apply the span to.
        $newlyCreatedTransaction = false;
        if (is_null($transaction)) {
            $transaction = self::getNewTransaction($name, $operation);
            $newlyCreatedTransaction = true;
        }

        // Next, we should create a span within the transaction to actually preform the measurement. Afterwards, we should
        // call the provided callable with the parameters and capture its output.
        $span = $transaction->createSpan($operation);
        $return = call_user_func_array($fn, $parameters);

        // Finish the preformance span after the return value has been captured.
        $transaction->finishSpan($span);
        
        // If there was no transaction provided, and a new transaction was created just for this measurement then we must
        // also finish that newly created transaction to avoid possible leaks/dangling transactions.
        if ($newlyCreatedTransaction) {
            $transaction->finish();
        }

        // Finally, return captured return value from the callable.
        return $return;
    }

    // These are the direct functions that can be used to signpost an expensive function for measurement purposes.
    // Example usage:
    /*

        $transaction = SentryPreformance::getNewTransaction("Some expensive operation", "example.operation");
        $span = $transaction->startSpan("example.sub_operation", "A nice (optional) description!");

        ... actually preform the expensive function calls such as heavy database modification etc ...

        $transaction->endSpan($span);
        $transaction->end();

    */
    // Warning:
    //  IT IS VITAL THAT ANY STARTED MEASUREMENT IS ENDED TO AVOID MEMORY LEAKS. USING THE "measureWrapper" FUNCTION
    //  WILL TAKE CARE OF THIS AUTOMATICALLY INSTEAD. IF YOU DECIDE TO CALL THIS MANUALLY PLEASE FOR THE LOVE OF CHRIST
    //  ALSO REMEMBER TO END IT AS SOON AS POSSIBLE.
    public static ?EasySentryTransaction $currentTransaction = null;
    public static function getNewTransaction(string $name, string $operation): Blackhole|EasySentryTransaction {

        // Setup context for the full transaction
        $transactionContext = new \Sentry\Tracing\TransactionContext();
        $transactionContext->setName($name);
        $transactionContext->setOp($operation);

        // Start the transaction with the given context, and then set the current transaction as the
        // current span so we can retrieve it later if needed.
        $transaction = \Sentry\startTransaction($transactionContext);
        \Sentry\SentrySdk::getCurrentHub()->setSpan($transaction);

        // Finally, return the created transaction wrapped in an EasySentryTransaction to make things slightly
        // easier later on.
        $easyTransaction = new EasySentryTransaction($transaction);
        self::$currentTransaction = $easyTransaction;

        return $easyTransaction;
    }

    // This can be used to allow for utilities to interact with whatever Transaction called them. If a Transaction
    // was found it will be automatically wrapped in the EasySentryTransaction handler. 
    static function getCurrentTransaction(): ?EasySentryTransaction {
        if (is_null(self::$currentTransaction)) { return null; }
        return self::$currentTransaction;
    }
    
    // Resolves the given span in the provided transaction. If a transaction is not provided the current transaction
    // is used instead.
    static function finishSpan(?\Sentry\Tracing\Span $span, ?EasySentryTransaction $transaction = null): void {
        if (is_null($span)) { return; } // If there is no provided span, we cannot resolve at all.
        if (is_null($transaction)) {
            
            // No transaction provided, use the current.
            if (is_null(self::$currentTransaction)) {

                // There is no provided transaction, and there is no current transaction to use for resolving.
                return;
            
            }
            $transaction = self::$currentTransaction;
        }

        $transaction->finishSpan($span);
    }
}

// Automatically finish the current transaction on shutdown.
register_shutdown_function(function() {
    if (!is_null(SentryPreformance::$currentTransaction)) {
        if (SentryPreformance::$currentTransaction->autoFinishOnShutdown) {
            SentryPreformance::$currentTransaction->finish();
        }
    }
});