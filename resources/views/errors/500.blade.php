@include('errors.layout', [
    'code' => 500,
    'heading' => 'Internal Server Error',
    'message' => 'An unexpected system error occurred and has been reported to the administrator.',
])
