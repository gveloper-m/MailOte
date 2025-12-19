<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Models\Email;
use App\Models\EmailBody;
use Google\Client as GoogleClient;
use Google\Service\Gmail;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class GmailController extends Controller
{
    protected GoogleClient $googleClient;

    public function __construct()
    {
        $this->googleClient = new GoogleClient();
        $this->googleClient->setClientId(config('services.google.client_id'));
        $this->googleClient->setClientSecret(config('services.google.client_secret'));
        $this->googleClient->setRedirectUri(config('services.google.redirect'));
        $this->googleClient->addScope(Gmail::MAIL_GOOGLE_COM);
        $this->googleClient->addScope('https://www.googleapis.com/auth/userinfo.email');
        $this->googleClient->addScope('https://www.googleapis.com/auth/userinfo.profile');
    }

    public function login(Request $request): JsonResponse
    {
        $authCode = $request->input('code');

        if (!$authCode) {
            return response()->json([
                'error' => 'No authorization code provided',
                'redirect_url' => $this->googleClient->createAuthUrl()
            ], 400);
        }

        try {
            // Exchange code for token
            $accessToken = $this->googleClient->fetchAccessTokenWithAuthCode($authCode);
            $this->googleClient->setAccessToken($accessToken);

            // Get user info
            $oauth2 = new \Google\Service\Oauth2($this->googleClient);
            $userInfo = $oauth2->userinfo->get();

            // Find or create user
            $user = User::updateOrCreate(
                ['google_id' => $userInfo->id],
                [
                    'name' => $userInfo->name,
                    'email' => $userInfo->email,
                    'google_token' => json_encode($accessToken),
                    'google_refresh_token' => $accessToken['refresh_token'] ?? null,
                ]
            );

            return response()->json([
                'success' => true,
                'message' => 'User logged in successfully',
                'user' => $user,
                'token' => base64_encode($user->id . ':' . $accessToken['access_token']),
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Authentication failed: ' . $e->getMessage()
            ], 401);
        }
    }

    public function callback(Request $request)
    {
        $authCode = $request->input('code');
        $error = $request->input('error');

        if ($error) {
            return redirect('/?error=' . urlencode($error));
        }

        if (!$authCode) {
            return redirect('/?error=No authorization code provided');
        }

        try {
            // Exchange code for token
            $accessToken = $this->googleClient->fetchAccessTokenWithAuthCode($authCode);
            $this->googleClient->setAccessToken($accessToken);

            // Get user info
            $oauth2 = new \Google\Service\Oauth2($this->googleClient);
            $userInfo = $oauth2->userinfo->get();

            // Find or create user
            $user = User::updateOrCreate(
                ['google_id' => $userInfo->id],
                [
                    'name' => $userInfo->name,
                    'email' => $userInfo->email,
                    'google_token' => json_encode($accessToken),
                    'google_refresh_token' => $accessToken['refresh_token'] ?? null,
                ]
            );

            // Create token for frontend
            $token = base64_encode($user->id . ':' . $accessToken['access_token']);

            // Redirect to frontend with token
            return redirect('/?token=' . urlencode($token) . '&user=' . urlencode($user->email));
        } catch (\Exception $e) {
            return redirect('/?error=' . urlencode('Authentication failed: ' . $e->getMessage()));
        }
    }

    public function getEmails(Request $request): JsonResponse
    {
        $token = $this->extractToken($request);

        if (!$token) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        try {
            [$userId, $accessToken] = explode(':', base64_decode($token), 2);
            
            $user = User::find($userId);
            if (!$user || !$user->google_token) {
                return response()->json(['error' => 'User not found or not authenticated'], 401);
            }

            // Set up Google Client with user token
            $tokenData = json_decode($user->google_token, true);
            $this->googleClient->setAccessToken($tokenData);

            // Refresh token if expired
            if ($this->googleClient->isAccessTokenExpired()) {
                if ($user->google_refresh_token) {
                    try {
                        $this->googleClient->refreshToken($user->google_refresh_token);
                        $newToken = $this->googleClient->getAccessToken();
                        $user->update(['google_token' => json_encode($newToken)]);
                    } catch (\Exception $e) {
                        return response()->json(['error' => 'Failed to refresh token: ' . $e->getMessage()], 401);
                    }
                } else {
                    return response()->json(['error' => 'Token expired and no refresh token available'], 401);
                }
            }

            // Get pagination parameters
            $pageSize = min((int) $request->input('page_size', 50), 500); // Max 500 per page
            $currentPage = (int) $request->input('page', 1);
            $pageToken = $request->input('page_token'); // Gmail page token for continuation

            // Get total email count (cached for 1 hour to avoid performance hit)
            $cacheKey = "gmail_total_count:{$user->id}";
            $totalEmails = cache()->get($cacheKey);
            
            if (!$totalEmails) {
                // Count total emails
                $gmail = new Gmail($this->googleClient);
                $countResult = $gmail->users_messages->listUsersMessages('me', [
                    'q' => 'in:inbox',
                    'maxResults' => 1,
                ]);
                $totalEmails = $countResult->getResultSizeEstimate() ?? 0;
                cache()->put($cacheKey, $totalEmails, now()->addHour());
            }

            $totalPages = (int) ceil($totalEmails / $pageSize);

            // Fetch emails directly from Gmail API for current page
            $gmail = new Gmail($this->googleClient);
            $messages = $gmail->users_messages->listUsersMessages('me', [
                'maxResults' => $pageSize,
                'q' => 'in:inbox',
                'pageToken' => $pageToken,
            ]);

            $emails = [];
            $nextPageToken = null;
            
            if ($messages->getMessages()) {
                foreach ($messages->getMessages() as $message) {
                    try {
                        $msg = $gmail->users_messages->get('me', $message->getId(), [
                            'format' => 'metadata',
                            'metadataHeaders' => ['From', 'To', 'Subject', 'Date']
                        ]);
                        $headers = $msg->getPayload()->getHeaders();
                        
                        $from = $this->getHeaderValue($headers, 'From') ?? 'Unknown';
                        $to = $this->getHeaderValue($headers, 'To') ?? 'Unknown';
                        $subject = $this->getHeaderValue($headers, 'Subject') ?? '(No subject)';
                        $date = $this->getHeaderValue($headers, 'Date') ?? 'Unknown';
                        
                        // Save email to database (or update if exists)
                        Email::updateOrCreate(
                            ['gmail_id' => $message->getId(), 'user_id' => $user->id],
                            [
                                'from' => $from,
                                'to' => $to,
                                'subject' => $subject,
                                'date' => $date,
                                'remote_delete' => false,
                            ]
                        );
                        
                        $email = [
                            'id' => $message->getId(),
                            'threadId' => $message->getThreadId(),
                            'from' => $from,
                            'to' => $to,
                            'subject' => $subject,
                            'date' => $date,
                        ];
                        
                        $emails[] = $email;
                    } catch (\Exception $e) {
                        // Skip emails that cause errors
                        continue;
                    }
                }
                
                // Get next page token for pagination
                $nextPageToken = $messages->getNextPageToken();
            }

            $response = [
                'success' => true,
                'user' => $user->email,
                'pagination' => [
                    'current_page' => $currentPage,
                    'page_size' => $pageSize,
                    'items_in_page' => count($emails),
                    'total_emails' => $totalEmails,
                    'total_pages' => $totalPages,
                    'has_more' => $nextPageToken ? true : false,
                ],
                'emails' => $emails,
            ];

            // Build URLs
            $baseUrl = $request->url();

            // Next page URL
            if ($nextPageToken) {
                $nextParams = [
                    'page_size' => $pageSize,
                    'page' => $currentPage + 1,
                    'page_token' => $nextPageToken,
                ];
                $response['pagination']['next_page_url'] = $baseUrl . '?' . http_build_query($nextParams);
            }

            // First page URL (always available)
            $firstParams = [
                'page_size' => $pageSize,
                'page' => 1,
            ];
            $response['pagination']['first_page_url'] = $baseUrl . '?' . http_build_query($firstParams);

            // Last page URL
            if ($totalPages > 1) {
                $lastParams = [
                    'page_size' => $pageSize,
                    'page' => $totalPages,
                ];
                $response['pagination']['last_page_url'] = $baseUrl . '?' . http_build_query($lastParams);
            }

            return response()->json($response);
        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Failed to fetch emails: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Fetch all emails from Gmail with optimized batching
     * Uses batch API calls to avoid timeout
     */
    private function fetchAllEmailsSlowly(): array
    {
        $gmail = new Gmail($this->googleClient);
        $allEmails = [];
        $pageToken = null;
        $batchSize = 50; // Fetch 50 at a time for listing
        $maxBatches = 10000; // Limit to prevent infinite loops
        $batchCount = 0;

        do {
            try {
                // List message IDs (fast operation)
                $messages = $gmail->users_messages->listUsersMessages('me', [
                    'maxResults' => $batchSize,
                    'q' => 'in:inbox',
                    'pageToken' => $pageToken,
                ]);

                if (!$messages->getMessages()) {
                    break;
                }

                // Fetch full details for each message ID
                foreach ($messages->getMessages() as $message) {
                    try {
                        $msg = $gmail->users_messages->get('me', $message->getId(), [
                            'format' => 'metadata',
                            'metadataHeaders' => ['From', 'To', 'Subject', 'Date']
                        ]);
                        $headers = $msg->getPayload()->getHeaders();
                        
                        $email = [
                            'id' => $message->getId(),
                            'threadId' => $message->getThreadId(),
                            'from' => $this->getHeaderValue($headers, 'From') ?? 'Unknown',
                            'to' => $this->getHeaderValue($headers, 'To') ?? 'Unknown',
                            'subject' => $this->getHeaderValue($headers, 'Subject') ?? '(No subject)',
                            'date' => $this->getHeaderValue($headers, 'Date') ?? 'Unknown',
                        ];
                        
                        $allEmails[] = $email;
                    } catch (\Exception $e) {
                        // Skip emails that cause errors
                        continue;
                    }
                }

                // Get next page token
                $pageToken = $messages->getNextPageToken();
                $batchCount++;
                
                // Small delay to avoid rate limiting (100ms per batch)
                if ($pageToken) {
                    usleep(100000); // 100ms between batches
                }

            } catch (\Exception $e) {
                // If we hit an error, break and return what we have
                break;
            }

        } while ($pageToken && $batchCount < $maxBatches);

        return $allEmails;
    }

    private function getHeaderValue($headers, $name): ?string
    {
        foreach ($headers as $header) {
            if ($header->getName() === $name) {
                return $header->getValue();
            }
        }
        return null;
    }

    private function getEmailBody($payload): ?string
    {
        // If it's a Message object, get the payload first
        if (method_exists($payload, 'getPayload')) {
            $payload = $payload->getPayload();
        }

        if ($payload->getParts()) {
            // Try to find HTML first, then plain text
            $htmlBody = null;
            $plainBody = null;
            
            foreach ($payload->getParts() as $part) {
                if ($part->getMimeType() === 'text/html') {
                    $data = $part->getBody()->getData();
                    if ($data) {
                        $htmlBody = base64_decode(strtr($data, '-_', '+/'));
                    }
                } elseif ($part->getMimeType() === 'text/plain' && !$plainBody) {
                    $data = $part->getBody()->getData();
                    if ($data) {
                        $plainBody = base64_decode(strtr($data, '-_', '+/'));
                    }
                }
            }
            
            // Return HTML if available, otherwise plain text
            if ($htmlBody) {
                return mb_convert_encoding($htmlBody, 'UTF-8', 'UTF-8');
            } elseif ($plainBody) {
                return mb_convert_encoding($plainBody, 'UTF-8', 'UTF-8');
            }
        }

        $body = $payload->getBody()->getData();
        if ($body) {
            try {
                $decoded = base64_decode(strtr($body, '-_', '+/'));
                return mb_convert_encoding($decoded, 'UTF-8', 'UTF-8');
            } catch (\Exception $e) {
                return null;
            }
        }
        return null;
    }

    private function extractToken(Request $request): ?string
    {
        $header = $request->header('Authorization');
        if ($header && str_starts_with($header, 'Bearer ')) {
            return substr($header, 7);
        }
        return $request->input('token');
    }

    public function findUnsubscribeEmails(Request $request): JsonResponse
    {
        $token = $this->extractToken($request);

        if (!$token) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        try {
            [$userId, $accessToken] = explode(':', base64_decode($token), 2);
            
            $user = User::find($userId);
            if (!$user || !$user->google_token) {
                return response()->json(['error' => 'User not found or not authenticated'], 401);
            }

            // Set up Google Client with user token
            $tokenData = json_decode($user->google_token, true);
            $this->googleClient->setAccessToken($tokenData);

            // Refresh token if expired
            if ($this->googleClient->isAccessTokenExpired()) {
                if ($user->google_refresh_token) {
                    try {
                        $this->googleClient->refreshToken($user->google_refresh_token);
                        $newToken = $this->googleClient->getAccessToken();
                        $user->update(['google_token' => json_encode($newToken)]);
                    } catch (\Exception $e) {
                        return response()->json(['error' => 'Failed to refresh token: ' . $e->getMessage()], 401);
                    }
                } else {
                    return response()->json(['error' => 'Token expired and no refresh token available'], 401);
                }
            }

            $gmail = new Gmail($this->googleClient);
            $unsubscribeEmails = [];
            $pageToken = null;
            $batchSize = 50;
            $maxBatches = 10000; // Unlimited - process all emails
            $batchCount = 0;

            do {
                try {
                    // List message IDs
                    $messages = $gmail->users_messages->listUsersMessages('me', [
                        'maxResults' => $batchSize,
                        'q' => 'in:inbox',
                        'pageToken' => $pageToken,
                    ]);

                    if (!$messages->getMessages()) {
                        break;
                    }

                    // Fetch full details for each message to check for unsubscribe
                    foreach ($messages->getMessages() as $message) {
                        try {
                            $msg = $gmail->users_messages->get('me', $message->getId(), [
                                'format' => 'full'
                            ]);

                            $payload = $msg->getPayload();
                            $headers = $payload->getHeaders();
                            
                            $unsubscribeLink = null;
                            
                            // First, check for RFC 2369 List-Unsubscribe header (most reliable)
                            $listUnsubscribe = $this->getHeaderValue($headers, 'List-Unsubscribe');
                            if ($listUnsubscribe) {
                                // Extract URL from <URL> format
                                if (preg_match('/<(https?:\/\/[^>]+)>/', $listUnsubscribe, $matches)) {
                                    $unsubscribeLink = $matches[1];
                                } elseif (preg_match('/(https?:\/\/\S+)/', $listUnsubscribe, $matches)) {
                                    $unsubscribeLink = $matches[1];
                                }
                            }
                            
                            // If no List-Unsubscribe header, check body
                            if (!$unsubscribeLink) {
                                $body = $this->getEmailBody($payload);
                                
                                // Only look for unsubscribe links if body contains the word "unsubscribe"
                                if ($body && stripos($body, 'unsubscribe') !== false) {
                                    $unsubscribeLink = $this->extractUnsubscribeLink($body);
                                }
                            }
                            
                            // Only add to results if we found a valid unsubscribe link
                            if ($unsubscribeLink) {
                                $from = $this->getHeaderValue($headers, 'From') ?? 'Unknown';
                                $to = $this->getHeaderValue($headers, 'To') ?? 'Unknown';
                                $subject = $this->getHeaderValue($headers, 'Subject') ?? '(No subject)';
                                $date = $this->getHeaderValue($headers, 'Date') ?? 'Unknown';
                                
                                // Save to database
                                $dbEmail = Email::updateOrCreate(
                                    ['gmail_id' => $message->getId(), 'user_id' => $user->id],
                                    [
                                        'from' => $from,
                                        'to' => $to,
                                        'subject' => $subject,
                                        'date' => $date,
                                        'remote_delete' => false,
                                    ]
                                );
                                
                                $email = [
                                    'id' => $message->getId(),
                                    'from' => $from,
                                    'subject' => $subject,
                                    'date' => $date,
                                    'unsubscribe_link' => $unsubscribeLink,
                                ];
                                
                                $unsubscribeEmails[] = $email;
                            }
                        } catch (\Exception $e) {
                            // Skip emails that cause errors
                            continue;
                        }
                    }

                    // Get next page token
                    $pageToken = $messages->getNextPageToken();
                    $batchCount++;
                    
                    // Small delay to avoid rate limiting
                    if ($pageToken) {
                        usleep(100000); // 100ms between batches
                    }

                } catch (\Exception $e) {
                    // If we hit an error, break and return what we have
                    break;
                }

            } while ($pageToken && $batchCount < $maxBatches);

            return response()->json([
                'success' => true,
                'user' => $user->email,
                'total_found' => count($unsubscribeEmails),
                'emails_with_unsubscribe' => $unsubscribeEmails,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Failed to fetch emails: ' . $e->getMessage()
            ], 500);
        }
    }

    private function extractEmailAddress(string $fromHeader): string
    {
        // Extract email from formats like:
        // "Name" <email@domain.com>
        // Name <email@domain.com>
        // email@domain.com
        
        if (preg_match('/<([^>]+)>/', $fromHeader, $matches)) {
            return trim($matches[1]);
        }
        
        // If no angle brackets, assume it's already an email
        return trim($fromHeader);
    }

    private function formatBytes(int $bytes, int $precision = 2): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $bytes = max($bytes, 0);
        $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
        $pow = min($pow, count($units) - 1);
        $bytes /= (1 << (10 * $pow));

        return round($bytes, $precision) . ' ' . $units[$pow];
    }

    private function extractUnsubscribeLink(string $body): ?string
    {
        // Decode HTML entities first
        $body = html_entity_decode($body, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        
        // Remove newlines and extra spaces around URLs to make matching easier
        $body = str_replace(["\r", "\n", "\t"], ' ', $body);
        
        // Pattern 1: Look for href in HTML with "unsubscribe" text in the link
        // This is the most reliable pattern - the link text says "unsubscribe"
        if (preg_match('/<a\s[^>]*href=["\']?(https?:\/\/[^"\'>\s]+)["\']?[^>]*>([^<]*unsubscribe[^<]*)<\/a>/i', $body, $matches)) {
            $url = $matches[1];
            $url = rtrim($url, '.,;:!?)\'"');
            if (filter_var($url, FILTER_VALIDATE_URL)) {
                return $url;
            }
        }
        
        // Pattern 2: Look for button with unsubscribe text
        if (preg_match('/<button\s[^>]*>([^<]*unsubscribe[^<]*)<\/button>/i', $body, $matches)) {
            // Try to find href or onclick in context around the button
            $pos = max(0, strpos($body, $matches[0]) - 200);
            $context = substr($body, $pos, 600);
            
            if (preg_match('/(https?:\/\/[^\s<>"{}|\\^`\[\]]+)/i', $context, $linkMatch)) {
                $url = $linkMatch[1];
                $url = rtrim($url, '.,;:!?)\'"');
                if (filter_var($url, FILTER_VALIDATE_URL)) {
                    return $url;
                }
            }
        }
        
        // Pattern 3: Look for List-Unsubscribe header format <URL> 
        if (preg_match('/<(https?:\/\/[^\s>]+)>/i', $body, $matches)) {
            $url = $matches[1];
            if (filter_var($url, FILTER_VALIDATE_URL)) {
                return $url;
            }
        }
        
        // Pattern 4: Look for markdown-style links [Unsubscribe](url)
        if (preg_match('/\[.*?unsubscribe.*?\]\((https?:\/\/[^\)]+)\)/i', $body, $matches)) {
            $url = $matches[1];
            if (filter_var($url, FILTER_VALIDATE_URL)) {
                return $url;
            }
        }
        
        // Pattern 5: Look for context around "unsubscribe" word with very strict matching
        // Only if the word "unsubscribe" appears directly before or after the URL
        if (preg_match('/unsubscribe["\'\s]*(?:here|link|now|at)?["\'\s:]*(?:https?:\/\/[^\s<>"{}|\\^`\[\]]+)/i', $body, $matches)) {
            if (preg_match('/(https?:\/\/[^\s<>"{}|\\^`\[\]]+)/i', $matches[0], $linkMatch)) {
                $url = $linkMatch[1];
                $url = rtrim($url, '.,;:!?)\'"');
                if (filter_var($url, FILTER_VALIDATE_URL)) {
                    return $url;
                }
            }
        }

        return null;
    }

    public function getEmail(Request $request, string $emailId): JsonResponse
    {
        $token = $this->extractToken($request);

        if (!$token) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        try {
            [$userId, $accessToken] = explode(':', base64_decode($token), 2);
            
            $user = User::find($userId);
            if (!$user || !$user->google_token) {
                return response()->json(['error' => 'User not found or not authenticated'], 401);
            }

            // Set up Google Client with user token
            $tokenData = json_decode($user->google_token, true);
            $this->googleClient->setAccessToken($tokenData);

            // Refresh token if expired
            if ($this->googleClient->isAccessTokenExpired()) {
                if ($user->google_refresh_token) {
                    try {
                        $this->googleClient->refreshToken($user->google_refresh_token);
                        $newToken = $this->googleClient->getAccessToken();
                        $user->update(['google_token' => json_encode($newToken)]);
                    } catch (\Exception $e) {
                        return response()->json(['error' => 'Failed to refresh token: ' . $e->getMessage()], 401);
                    }
                } else {
                    return response()->json(['error' => 'Token expired and no refresh token available'], 401);
                }
            }

            // Fetch the full email with all content
            $gmail = new Gmail($this->googleClient);
            $message = $gmail->users_messages->get('me', $emailId, [
                'format' => 'full'
            ]);

            // Extract headers
            $headers = $message->getPayload()->getHeaders();
            
            $email = [
                'id' => $message->getId(),
                'threadId' => $message->getThreadId(),
                'from' => $this->getHeaderValue($headers, 'From') ?? 'Unknown',
                'to' => $this->getHeaderValue($headers, 'To') ?? 'Unknown',
                'subject' => $this->getHeaderValue($headers, 'Subject') ?? '(No subject)',
                'date' => $this->getHeaderValue($headers, 'Date') ?? 'Unknown',
                'cc' => $this->getHeaderValue($headers, 'Cc'),
                'bcc' => $this->getHeaderValue($headers, 'Bcc'),
                'body' => $this->getEmailBody($message->getPayload()),
                'snippet' => $message->getSnippet(),
                'labels' => $message->getLabelIds() ?? [],
                'size_estimate' => $message->getSizeEstimate(),
            ];

            return response()->json([
                'success' => true,
                'user' => $user->email,
                'email' => $email,
            ]);
        } catch (\Google\Service\Exception $e) {
            // Handle Gmail API errors
            $error = json_decode($e->getMessage(), true);
            if ($error && $error['error']['code'] === 404) {
                return response()->json(['error' => 'Email not found'], 404);
            }
            return response()->json(['error' => 'Gmail API error: ' . $e->getMessage()], 500);
        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Failed to fetch email: ' . $e->getMessage()
            ], 500);
        }
    }

    public function unsubscribeFromEmails(Request $request): JsonResponse
    {
        $token = $this->extractToken($request);

        if (!$token) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        try {
            [$userId, $accessToken] = explode(':', base64_decode($token), 2);
            
            $user = User::find($userId);
            if (!$user || !$user->google_token) {
                return response()->json(['error' => 'User not found or not authenticated'], 401);
            }

            // Get the list of email IDs to unsubscribe from
            $ids = $request->input('ids');
            $unsubscribeAll = $request->input('unsubscribe_all', false);
            $shouldDelete = (bool) $request->input('delete', 0);
            
            if (!is_array($ids) && !$unsubscribeAll) {
                return response()->json(['error' => 'ids array or unsubscribe_all flag required'], 400);
            }

            // Set up Google Client with user token
            $tokenData = json_decode($user->google_token, true);
            $this->googleClient->setAccessToken($tokenData);

            // Refresh token if expired
            if ($this->googleClient->isAccessTokenExpired()) {
                if ($user->google_refresh_token) {
                    try {
                        $this->googleClient->refreshToken($user->google_refresh_token);
                        $newToken = $this->googleClient->getAccessToken();
                        $user->update(['google_token' => json_encode($newToken)]);
                    } catch (\Exception $e) {
                        return response()->json(['error' => 'Failed to refresh token: ' . $e->getMessage()], 401);
                    }
                } else {
                    return response()->json(['error' => 'Token expired and no refresh token available'], 401);
                }
            }

            // If unsubscribe_all is true, get all emails with unsubscribe links
            if ($unsubscribeAll) {
                $ids = [];
                $gmail = new Gmail($this->googleClient);
                $pageToken = null;
                $batchSize = 50;
                $maxBatches = 10000;
                $batchCount = 0;

                do {
                    try {
                        $messages = $gmail->users_messages->listUsersMessages('me', [
                            'maxResults' => $batchSize,
                            'q' => 'in:inbox',
                            'pageToken' => $pageToken,
                        ]);

                        if (!$messages->getMessages()) {
                            break;
                        }

                        foreach ($messages->getMessages() as $message) {
                            try {
                                $msg = $gmail->users_messages->get('me', $message->getId(), ['format' => 'full']);
                                $body = $this->getEmailBody($msg->getPayload());
                                
                                if ($body && stripos($body, 'unsubscribe') !== false) {
                                    $unsubscribeLink = $this->extractUnsubscribeLink($body);
                                    if ($unsubscribeLink) {
                                        $ids[] = [
                                            'id' => $message->getId(),
                                            'link' => $unsubscribeLink
                                        ];
                                    }
                                }
                            } catch (\Exception $e) {
                                continue;
                            }
                        }

                        $pageToken = $messages->getNextPageToken();
                        $batchCount++;
                        
                        if ($pageToken) {
                            usleep(100000);
                        }
                    } catch (\Exception $e) {
                        break;
                    }
                } while ($pageToken && $batchCount < $maxBatches);
            }

            // Now unsubscribe from each email
            $successCount = 0;
            $failureCount = 0;
            $results = [];

            $gmail = new Gmail($this->googleClient);

            foreach ($ids as $item) {
                $emailId = is_array($item) ? $item['id'] : $item;
                $unsubscribeLink = is_array($item) ? $item['link'] : null;

                try {
                    // Get the email to extract unsubscribe link and sender if not provided
                    $msg = $gmail->users_messages->get('me', $emailId, ['format' => 'full']);
                    $payload = $msg->getPayload();
                    $headers = $payload->getHeaders();
                    
                    if (!$unsubscribeLink) {
                        $body = $this->getEmailBody($payload);
                        
                        if ($body && stripos($body, 'unsubscribe') !== false) {
                            $unsubscribeLink = $this->extractUnsubscribeLink($body);
                        }
                    }
                    
                    // Get the sender email address
                    $senderEmail = $this->getHeaderValue($headers, 'From');

                    if ($unsubscribeLink) {
                        // Make request to unsubscribe link
                        try {
                            $client = new \GuzzleHttp\Client([
                                'timeout' => 5,
                                'connect_timeout' => 3,
                                'http_errors' => false,
                                'allow_redirects' => true,
                            ]);
                            $client->get($unsubscribeLink);
                            
                            // Only delete emails if delete flag is 1
                            if ($shouldDelete) {
                                // Delete ALL emails from this sender after successful unsubscribe
                                try {
                                    // Extract just the email address from the From header
                                    // e.g., "Glassdoor <info@glassdoor.com>" -> "info@glassdoor.com"
                                    $emailAddress = $this->extractEmailAddress($senderEmail);
                                    
                                    // Find all emails from this sender using pagination
                                    $allEmailIds = [];
                                    $pageToken = null;
                                    
                                    do {
                                        try {
                                            $senderEmails = $gmail->users_messages->listUsersMessages('me', [
                                                'q' => 'from:' . $emailAddress,
                                                'maxResults' => 100,
                                                'pageToken' => $pageToken,
                                            ]);
                                            
                                            if ($senderEmails->getMessages()) {
                                                foreach ($senderEmails->getMessages() as $emailToDelete) {
                                                    $allEmailIds[] = $emailToDelete->getId();
                                                }
                                            }
                                            
                                            $pageToken = $senderEmails->getNextPageToken();
                                        } catch (\Exception $e) {
                                            break;
                                        }
                                    } while ($pageToken);
                                    
                                    // Delete all found emails
                                    $deletedCount = 0;
                                    foreach ($allEmailIds as $deleteId) {
                                        try {
                                            // Fetch full email to save body
                                            $emailToSave = $gmail->users_messages->get('me', $deleteId, ['format' => 'full']);
                                            $emailBody = $this->getEmailBody($emailToSave->getPayload());
                                            $payload = $emailToSave->getPayload();
                                            $headers = $payload->getHeaders();
                                            
                                            // Ensure email exists in database
                                            $dbEmail = Email::updateOrCreate(
                                                ['gmail_id' => $deleteId, 'user_id' => $user->id],
                                                [
                                                    'from' => $this->getHeaderValue($headers, 'From') ?? 'Unknown',
                                                    'to' => $this->getHeaderValue($headers, 'To') ?? 'Unknown',
                                                    'subject' => $this->getHeaderValue($headers, 'Subject') ?? '(No subject)',
                                                    'date' => $this->getHeaderValue($headers, 'Date') ?? 'Unknown',
                                                    'remote_delete' => false,
                                                ]
                                            );
                                            
                                            // Save body if we have one
                                            if ($emailBody) {
                                                EmailBody::updateOrCreate(
                                                    ['email_id' => $dbEmail->id],
                                                    ['body' => $emailBody]
                                                );
                                            }
                                            
                                            // Mark as remote deleted
                                            $dbEmail->update(['remote_delete' => true]);
                                            
                                            // Delete from Gmail
                                            $gmail->users_messages->delete('me', $deleteId);
                                            $deletedCount++;
                                        } catch (\Exception $e) {
                                            // Continue deleting others even if one fails
                                            continue;
                                        }
                                    }
                                    
                                    $successCount++;
                                    $results[] = [
                                        'id' => $emailId,
                                        'sender' => $senderEmail,
                                        'sender_email' => $emailAddress,
                                        'status' => 'success',
                                        'link' => $unsubscribeLink,
                                        'deleted_count' => $deletedCount,
                                        'message' => "Unsubscribed and deleted $deletedCount email(s) from $emailAddress"
                                    ];
                                } catch (\Exception $deleteException) {
                                    $successCount++;
                                    $results[] = [
                                        'id' => $emailId,
                                        'sender' => $senderEmail,
                                        'status' => 'success',
                                        'link' => $unsubscribeLink,
                                        'deleted_count' => 0,
                                        'delete_error' => $deleteException->getMessage(),
                                        'message' => 'Unsubscribed but failed to delete emails'
                                    ];
                                }
                            } else {
                                // Just unsubscribe without deleting
                                $successCount++;
                                $results[] = [
                                    'id' => $emailId,
                                    'sender' => $senderEmail,
                                    'status' => 'success',
                                    'link' => $unsubscribeLink,
                                    'deleted_count' => 0,
                                    'message' => 'Unsubscribed successfully (delete flag not set)'
                                ];
                            }
                        } catch (\Exception $e) {
                            $failureCount++;
                            $results[] = [
                                'id' => $emailId,
                                'sender' => $senderEmail,
                                'status' => 'failed',
                                'reason' => $e->getMessage()
                            ];
                        }
                    } else {
                        $failureCount++;
                        $results[] = [
                            'id' => $emailId,
                            'sender' => $senderEmail,
                            'status' => 'failed',
                            'reason' => 'No unsubscribe link found'
                        ];
                    }
                } catch (\Exception $e) {
                    $failureCount++;
                    $results[] = [
                        'id' => $emailId,
                        'status' => 'failed',
                        'reason' => $e->getMessage()
                    ];
                }
                
                usleep(100000); // 100ms delay between requests to avoid rate limiting
            }

            return response()->json([
                'success' => true,
                'user' => $user->email,
                'total_processed' => count($ids),
                'success_count' => $successCount,
                'failure_count' => $failureCount,
                'results' => $results,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Failed to unsubscribe: ' . $e->getMessage()
            ], 500);
        }
    }

    public function getDeletedEmails(Request $request): JsonResponse
    {
        $token = $this->extractToken($request);

        if (!$token) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        try {
            [$userId, $accessToken] = explode(':', base64_decode($token), 2);
            
            $user = User::find($userId);
            if (!$user || !$user->google_token) {
                return response()->json(['error' => 'User not found or not authenticated'], 401);
            }

            // Get pagination parameters
            $pageSize = min((int) $request->input('page_size', 50), 500);
            $page = (int) $request->input('page', 1);

            // Fetch deleted emails from database with pagination
            $deletedEmailsQuery = Email::where('user_id', $user->id)
                ->where('remote_delete', true)
                ->with('body')
                ->orderBy('date', 'desc');

            $totalDeleted = $deletedEmailsQuery->count();
            $totalPages = (int) ceil($totalDeleted / $pageSize);

            $deletedEmails = $deletedEmailsQuery
                ->paginate($pageSize, ['*'], 'page', $page)
                ->items();

            $emails = [];
            foreach ($deletedEmails as $email) {
                $emailData = [
                    'id' => $email->id,
                    'gmail_id' => $email->gmail_id,
                    'from' => $email->from,
                    'to' => $email->to,
                    'subject' => $email->subject,
                    'date' => $email->date,
                    'remote_delete' => $email->remote_delete,
                    'body' => $email->body ? $email->body->body : null,
                    'deleted_at' => $email->updated_at,
                ];
                $emails[] = $emailData;
            }

            return response()->json([
                'success' => true,
                'user' => $user->email,
                'pagination' => [
                    'current_page' => $page,
                    'page_size' => $pageSize,
                    'items_in_page' => count($emails),
                    'total_deleted' => $totalDeleted,
                    'total_pages' => $totalPages,
                    'has_more' => $page < $totalPages,
                ],
                'deleted_emails' => $emails,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Failed to fetch deleted emails: ' . $e->getMessage()
            ], 500);
        }
    }

    public function statistics(Request $request): JsonResponse|StreamedResponse
    {
        $token = $this->extractToken($request);

        if (!$token) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        try {
            [$userId, $accessToken] = explode(':', base64_decode($token), 2);
            
            $user = User::find($userId);
            if (!$user || !$user->google_token) {
                return response()->json(['error' => 'User not found or not authenticated'], 401);
            }

            // Set up Google Client with user token
            $tokenData = json_decode($user->google_token, true);
            $this->googleClient->setAccessToken($tokenData);

            // Refresh token if expired
            if ($this->googleClient->isAccessTokenExpired()) {
                if ($user->google_refresh_token) {
                    try {
                        $this->googleClient->refreshToken($user->google_refresh_token);
                        $newToken = $this->googleClient->getAccessToken();
                        $user->update(['google_token' => json_encode($newToken)]);
                    } catch (\Exception $e) {
                        return response()->json(['error' => 'Failed to refresh token'], 401);
                    }
                } else {
                    return response()->json(['error' => 'Token expired'], 401);
                }
            }

            $gmail = new Gmail($this->googleClient);

            // Check if this is first time or if we need to refresh
            $savedEmailsCount = Email::where('user_id', $user->id)->count();
            $shouldFetchAll = $request->input('refresh', false) || $savedEmailsCount === 0;

            if ($shouldFetchAll) {
                // First time: Fetch all emails from Gmail and save them
                $pageToken = null;
                $batchSize = 100;
                $maxBatches = 50;
                $batchCount = 0;

                do {
                    try {
                        $messages = $gmail->users_messages->listUsersMessages('me', [
                            'maxResults' => $batchSize,
                            'pageToken' => $pageToken,
                        ]);

                        if (!$messages->getMessages()) {
                            break;
                        }

                        foreach ($messages->getMessages() as $message) {
                            try {
                                $msg = $gmail->users_messages->get('me', $message->getId(), ['format' => 'full']);
                                $payload = $msg->getPayload();
                                $headers = $payload->getHeaders();
                                
                                // Save or update email
                                $emailRecord = Email::updateOrCreate(
                                    ['gmail_id' => $message->getId(), 'user_id' => $user->id],
                                    [
                                        'from' => $this->getHeaderValue($headers, 'From') ?? 'Unknown',
                                        'to' => $this->getHeaderValue($headers, 'To') ?? 'Unknown',
                                        'subject' => $this->getHeaderValue($headers, 'Subject') ?? '(No subject)',
                                        'date' => $this->getHeaderValue($headers, 'Date') ?? now(),
                                        'thread_id' => $msg->getThreadId(),
                                        'snippet' => $msg->getSnippet() ?? '',
                                        'label_ids' => json_encode($msg->getLabelIds() ?? []),
                                        'has_attachments' => $payload->getParts() ? true : false,
                                        'remote_delete' => false,
                                    ]
                                );
                                
                                // Save body
                                $emailBody = $this->getEmailBody($payload);
                                if ($emailBody) {
                                    EmailBody::updateOrCreate(
                                        ['email_id' => $emailRecord->id],
                                        ['body' => $emailBody]
                                    );
                                }
                            } catch (\Exception $e) {
                                continue;
                            }
                        }

                        $pageToken = $messages->getNextPageToken();
                        $batchCount++;
                        
                        if ($pageToken) {
                            usleep(50000);
                        }
                    } catch (\Exception $e) {
                        break;
                    }
                } while ($pageToken && $batchCount < $maxBatches);
            }

            // Now fetch all emails from database for this user
            $allEmails = Email::where('user_id', $user->id)->get();
            $stats = [];

            // ===== 1. Total Emails =====
            $stats['total_emails'] = $allEmails->count();

            // ===== 2. Unread Emails =====
            $unreadCount = $allEmails->filter(function($email) {
                $labels = json_decode($email->label_ids, true) ?? [];
                return in_array('UNREAD', $labels);
            })->count();
            $stats['unread_emails'] = $unreadCount;

            // ===== 3. Spam Emails =====
            $spamCount = $allEmails->filter(function($email) {
                $labels = json_decode($email->label_ids, true) ?? [];
                return in_array('SPAM', $labels);
            })->count();
            $stats['spam_emails'] = $spamCount;

            // ===== 4. Emails by Category =====
            $stats['emails_by_category'] = [
                'Primary' => $allEmails->filter(function($email) { $labels = json_decode($email->label_ids, true) ?? []; return in_array('CATEGORY_PRIMARY', $labels); })->count(),
                'Social' => $allEmails->filter(function($email) { $labels = json_decode($email->label_ids, true) ?? []; return in_array('CATEGORY_SOCIAL', $labels); })->count(),
                'Promotions' => $allEmails->filter(function($email) { $labels = json_decode($email->label_ids, true) ?? []; return in_array('CATEGORY_PROMOTIONS', $labels); })->count(),
                'Updates' => $allEmails->filter(function($email) { $labels = json_decode($email->label_ids, true) ?? []; return in_array('CATEGORY_UPDATES', $labels); })->count(),
                'Forums' => $allEmails->filter(function($email) { $labels = json_decode($email->label_ids, true) ?? []; return in_array('CATEGORY_FORUMS', $labels); })->count(),
            ];

            // ===== 5. Emails with Attachments =====
            $stats['emails_with_attachments'] = $allEmails->where('has_attachments', true)->count();

            // ===== 6. Unsubscribed Emails =====
            $unsubscribedCount = $allEmails->filter(function($email) {
                return stripos($email->snippet, 'unsubscribe') !== false;
            })->count();
            $stats['unsubscribed_emails'] = $unsubscribedCount;
            $stats['unsubscribed_percentage'] = $stats['total_emails'] > 0 ? round(($unsubscribedCount / $stats['total_emails']) * 100, 2) : 0;

            // ===== 7. Emails by Sender =====
            $senderCounts = [];
            foreach ($allEmails as $email) {
                $from = $this->extractEmailAddress($email->from);
                $senderCounts[$from] = ($senderCounts[$from] ?? 0) + 1;
            }
            arsort($senderCounts);
            $stats['emails_by_sender'] = array_slice($senderCounts, 0, 20);

            // ===== 8. Top 5 Most Frequent Senders =====
            $stats['top_5_senders'] = array_slice($senderCounts, 0, 5, true);

            // ===== 9. Emails by Age =====
            $now = new \DateTime();
            $ageBreakdown = [
                'past_day' => 0,
                'past_week' => 0,
                'past_month' => 0,
                'past_3_months' => 0,
                'past_year' => 0,
                'older' => 0,
            ];

            foreach ($allEmails as $email) {
                if (!$email->date) continue;
                
                try {
                    $emailDate = new \DateTime($email->date);
                    $diff = $now->diff($emailDate);
                    $days = $diff->days;
                    
                    if ($days <= 1) {
                        $ageBreakdown['past_day']++;
                    } elseif ($days <= 7) {
                        $ageBreakdown['past_week']++;
                    } elseif ($days <= 30) {
                        $ageBreakdown['past_month']++;
                    } elseif ($days <= 90) {
                        $ageBreakdown['past_3_months']++;
                    } elseif ($days <= 365) {
                        $ageBreakdown['past_year']++;
                    } else {
                        $ageBreakdown['older']++;
                    }
                } catch (\Exception $e) {
                    continue;
                }
            }
            $stats['emails_by_age'] = $ageBreakdown;

            // ===== 10. Time of Day with Most Emails =====
            $timeOfDayCount = array_fill(0, 24, 0);
            foreach ($allEmails as $email) {
                if (!$email->date) continue;
                
                try {
                    $emailDate = new \DateTime($email->date);
                    $hour = (int) $emailDate->format('H');
                    $timeOfDayCount[$hour]++;
                } catch (\Exception $e) {
                    continue;
                }
            }
            $peakHour = array_search(max($timeOfDayCount), $timeOfDayCount);
            $stats['time_of_day_with_most_emails'] = [
                'peak_hour' => $peakHour . ':00',
                'count' => $timeOfDayCount[$peakHour],
                'hourly_breakdown' => $timeOfDayCount,
            ];

            // ===== 11. Emails with Keywords =====
            $keywords = ['sale', 'discount', 'urgent', 'free', 'limited', 'offer', 'exclusive'];
            $keywordCount = [];
            foreach ($keywords as $keyword) {
                $count = $allEmails->filter(function($email) use ($keyword) {
                    return stripos($email->subject, $keyword) !== false || stripos($email->snippet, $keyword) !== false;
                })->count();
                $keywordCount[$keyword] = $count;
            }
            $stats['emails_with_keywords'] = $keywordCount;

            // ===== 12. Promotions/Offers Detection =====
            $promotionKeywords = ['sale', 'discount', 'offer', 'promotion', 'deal', 'free', 'limited'];
            $promotionCount = $allEmails->filter(function($email) use ($promotionKeywords) {
                $emailContent = strtolower($email->subject . ' ' . $email->snippet);
                foreach ($promotionKeywords as $keyword) {
                    if (stripos($emailContent, $keyword) !== false) {
                        return true;
                    }
                }
                return false;
            })->count();
            $stats['emails_with_promotions'] = $promotionCount;

            // ===== 13. Newsletter Detection =====
            $newsletterKeywords = ['newsletter', 'unsubscribe', 'marketing', 'subscription', 'digest', 'weekly'];
            $newsletterCount = $allEmails->filter(function($email) use ($newsletterKeywords) {
                $emailContent = strtolower($email->subject . ' ' . $email->snippet);
                foreach ($newsletterKeywords as $keyword) {
                    if (stripos($emailContent, $keyword) !== false) {
                        return true;
                    }
                }
                return false;
            })->count();
            $stats['emails_from_newsletters'] = $newsletterCount;

            // ===== 14. Thread Analysis =====
            $threadCounts = [];
            foreach ($allEmails as $email) {
                if (!$email->thread_id) continue;
                $threadCounts[$email->thread_id] = ($threadCounts[$email->thread_id] ?? 0) + 1;
            }
            arsort($threadCounts);
            $longestThreadId = array_key_first($threadCounts) ?? null;
            $stats['longest_thread'] = [
                'thread_id' => $longestThreadId,
                'message_count' => $longestThreadId ? $threadCounts[$longestThreadId] : 0,
            ];

            // ===== 15. Email Open Rate (Estimated from labels) =====
            $openedEstimate = $allEmails->filter(function($email) {
                $labels = json_decode($email->label_ids, true) ?? [];
                return !in_array('UNREAD', $labels);
            })->count();
            $totalFetched = $stats['total_emails'];
            $stats['email_open_rate'] = [
                'estimated_opened' => $openedEstimate,
                'estimated_unopened' => $totalFetched - $openedEstimate,
                'percentage_opened' => $totalFetched > 0 ? round(($openedEstimate / $totalFetched) * 100, 2) : 0,
            ];

            // Check if export is requested
            if ($request->input('export') == 1) {
                return $this->exportStatisticsToExcel($stats, $user->email);
            }

            return response()->json([
                'user' => $user->email,
                'statistics' => $stats,
                'emails_analyzed' => $totalFetched,
                'data_source' => $shouldFetchAll ? 'freshly_saved_from_gmail' : 'cached_from_database',
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Failed to fetch statistics: ' . $e->getMessage()
            ], 500);
        }
    }

    public function senders(Request $request): JsonResponse
    {
        $token = $this->extractToken($request);

        if (!$token) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        try {
            [$userId, $accessToken] = explode(':', base64_decode($token), 2);
            
            $user = User::find($userId);
            if (!$user || !$user->google_token) {
                return response()->json(['error' => 'User not found or not authenticated'], 401);
            }

            // Get all emails for this user and group by sender
            $senders = Email::where('user_id', $user->id)
                ->select('from')
                ->distinct()
                ->orderBy('from')
                ->get()
                ->map(function($email) {
                    return [
                        'from' => $email->from,
                        'email' => $this->extractEmailAddress($email->from),
                    ];
                })
                ->values();

            // Get sender statistics
            $senderStats = Email::where('user_id', $user->id)
                ->select('from')
                ->selectRaw('COUNT(*) as count')
                ->selectRaw('MAX(date) as last_email_date')
                ->groupBy('from')
                ->orderByRaw('COUNT(*) DESC')
                ->get();

            // Merge stats with senders list
            $sendersWithStats = $senders->map(function($sender) use ($senderStats) {
                $stat = $senderStats->firstWhere('from', $sender['from']);
                return [
                    'from' => $sender['from'],
                    'email' => $sender['email'],
                    'total_emails' => $stat ? $stat->count : 0,
                    'last_email_date' => $stat ? $stat->last_email_date : null,
                ];
            })->sortByDesc('total_emails')->values();

            return response()->json([
                'success' => true,
                'user' => $user->email,
                'total_senders' => $sendersWithStats->count(),
                'pagination' => [
                    'page' => 1,
                    'page_size' => $sendersWithStats->count(),
                    'total' => $sendersWithStats->count(),
                ],
                'senders' => $sendersWithStats,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Failed to fetch senders: ' . $e->getMessage()
            ], 500);
        }
    }

    public function getEmailsWithAttachments(Request $request): JsonResponse
    {
        $token = $this->extractToken($request);

        if (!$token) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        try {
            [$userId, $accessToken] = explode(':', base64_decode($token), 2);
            
            $user = User::find($userId);
            if (!$user || !$user->google_token) {
                return response()->json(['error' => 'User not found or not authenticated'], 401);
            }

            // Set up Google Client with user token
            $tokenData = json_decode($user->google_token, true);
            $this->googleClient->setAccessToken($tokenData);

            // Refresh token if expired
            if ($this->googleClient->isAccessTokenExpired()) {
                if ($user->google_refresh_token) {
                    try {
                        $this->googleClient->refreshToken($user->google_refresh_token);
                        $newToken = $this->googleClient->getAccessToken();
                        $user->update(['google_token' => json_encode($newToken)]);
                    } catch (\Exception $e) {
                        return response()->json(['error' => 'Failed to refresh token: ' . $e->getMessage()], 401);
                    }
                } else {
                    return response()->json(['error' => 'Token expired and no refresh token available'], 401);
                }
            }

            $service = new Gmail($this->googleClient);

            // Get pagination parameters
            $pageSize = min((int) $request->input('page_size', 50), 500);
            $pageToken = $request->input('page_token', null);

            // Fetch emails with attachments from Gmail API
            $messages = $service->users_messages->listUsersMessages('me', [
                'q' => 'has:attachment',
                'maxResults' => $pageSize,
                'pageToken' => $pageToken,
            ]);

            $emailsList = [];
            $totalEmails = $messages->getResultSizeEstimate() ?? 0;

            if ($messages->getMessages()) {
                foreach ($messages->getMessages() as $messageInfo) {
                    $message = $service->users_messages->get('me', $messageInfo->getId(), ['format' => 'full']);
                    $headers = $message->getPayload()->getHeaders();
                    
                    // Extract headers
                    $from = '';
                    $to = '';
                    $subject = '';
                    $date = '';
                    
                    foreach ($headers as $header) {
                        if ($header->getName() === 'From') $from = $header->getValue();
                        if ($header->getName() === 'To') $to = $header->getValue();
                        if ($header->getName() === 'Subject') $subject = $header->getValue();
                        if ($header->getName() === 'Date') $date = $header->getValue();
                    }

                    // Parse date
                    try {
                        $date = \Carbon\Carbon::parse($date)->toIso8601String();
                    } catch (\Exception $e) {
                        $date = now()->toIso8601String();
                    }

                    // Get attachments
                    $attachments = [];
                    $payload = $message->getPayload();
                    $parts = $payload->getParts();

                    if ($parts) {
                        foreach ($parts as $part) {
                            $filename = $part->getFilename();
                            if ($filename) {
                                $mimeType = $part->getMimeType();
                                $size = $part->getBody()->getSize() ?? 0;
                                $partId = $part->getPartId();
                                
                                // Create download URL
                                $downloadUrl = route('gmail.attachment.download', [
                                    'gmail_id' => $messageInfo->getId(),
                                    'part_id' => $partId,
                                ]);
                                
                                $attachments[] = [
                                    'filename' => $filename,
                                    'mime_type' => $mimeType,
                                    'size' => $size,
                                    'size_formatted' => $this->formatBytes($size),
                                    'download_url' => $downloadUrl,
                                ];
                            }
                        }
                    }

                    if (count($attachments) > 0) {
                        $emailsList[] = [
                            'gmail_id' => $messageInfo->getId(),
                            'from' => $from,
                            'to' => $to,
                            'subject' => $subject,
                            'date' => $date,
                            'attachment_count' => count($attachments),
                            'attachments' => $attachments,
                        ];
                    }
                }
            }

            return response()->json([
                'success' => true,
                'user' => $user->email,
                'total_emails_with_attachments' => $totalEmails,
                'current_batch_count' => count($emailsList),
                'page_size' => $pageSize,
                'next_page_token' => $messages->getNextPageToken(),
                'emails' => $emailsList,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Failed to fetch emails with attachments: ' . $e->getMessage()
            ], 500);
        }
    }

    public function showSenderEmails(Request $request): JsonResponse|StreamedResponse
    {
        $token = $this->extractToken($request);

        if (!$token) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        try {
            [$userId, $accessToken] = explode(':', base64_decode($token), 2);
            
            $user = User::find($userId);
            if (!$user || !$user->google_token) {
                return response()->json(['error' => 'User not found or not authenticated'], 401);
            }

            // Get sender emails from request
            $senderEmails = $request->input('emails', []);
            
            if (!is_array($senderEmails) || count($senderEmails) === 0) {
                return response()->json(['error' => 'emails array required'], 400);
            }

            // Get pagination parameters
            $pageSize = min((int) $request->input('page_size', 50), 500);
            $page = (int) $request->input('page', 1);

            // Build query to find all emails from given senders TO current user (excluding deleted)
            $query = Email::where('user_id', $user->id)
                ->where('remote_delete', false)
                ->where('to', 'ILIKE', '%' . $user->email . '%');
            
            $query->where(function($q) use ($senderEmails) {
                foreach ($senderEmails as $senderEmail) {
                    // Match sender by extracted email address or full from field
                    $q->orWhere('from', 'ILIKE', '%' . $senderEmail . '%');
                }
            });

            $totalEmails = $query->count();
            $totalPages = (int) ceil($totalEmails / $pageSize);

            $emails = $query
                ->with('body')
                ->orderBy('date', 'desc')
                ->paginate($pageSize, ['*'], 'page', $page)
                ->items();

            $emailsList = [];
            foreach ($emails as $email) {
                $emailsList[] = [
                    'id' => $email->id,
                    'gmail_id' => $email->gmail_id,
                    'from' => $email->from,
                    'to' => $email->to,
                    'subject' => $email->subject,
                    'date' => $email->date,
                    'snippet' => $email->snippet,
                    'body' => $email->body ? $email->body->body : null,
                ];
            }

            // Check if export is requested
            if ($request->input('export') == 1) {
                return $this->exportSenderEmails($emailsList, $senderEmails, $user->email);
            }

            return response()->json([
                'success' => true,
                'user' => $user->email,
                'searched_senders' => $senderEmails,
                'pagination' => [
                    'current_page' => $page,
                    'page_size' => $pageSize,
                    'items_in_page' => count($emailsList),
                    'total_emails' => $totalEmails,
                    'total_pages' => $totalPages,
                    'has_more' => $page < $totalPages,
                ],
                'emails' => $emailsList,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Failed to fetch sender emails: ' . $e->getMessage()
            ], 500);
        }
    }

    public function deleteSenderEmails(Request $request): JsonResponse
    {
        $token = $this->extractToken($request);

        if (!$token) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        try {
            [$userId, $accessToken] = explode(':', base64_decode($token), 2);
            
            $user = User::find($userId);
            if (!$user || !$user->google_token) {
                return response()->json(['error' => 'User not found or not authenticated'], 401);
            }

            // Set up Google Client with user token
            $tokenData = json_decode($user->google_token, true);
            $this->googleClient->setAccessToken($tokenData);

            // Refresh token if expired
            if ($this->googleClient->isAccessTokenExpired()) {
                if ($user->google_refresh_token) {
                    try {
                        $this->googleClient->refreshToken($user->google_refresh_token);
                        $newToken = $this->googleClient->getAccessToken();
                        $user->update(['google_token' => json_encode($newToken)]);
                    } catch (\Exception $e) {
                        return response()->json(['error' => 'Failed to refresh token'], 401);
                    }
                } else {
                    return response()->json(['error' => 'Token expired'], 401);
                }
            }

            // Get sender emails from request
            $senderEmails = $request->input('emails', []);
            
            if (!is_array($senderEmails) || count($senderEmails) === 0) {
                return response()->json(['error' => 'emails array required'], 400);
            }

            // Find all emails from given senders TO current user (not already deleted)
            $query = Email::where('user_id', $user->id)
                ->where('remote_delete', false)
                ->where('to', 'ILIKE', '%' . $user->email . '%');
            
            $query->where(function($q) use ($senderEmails) {
                foreach ($senderEmails as $senderEmail) {
                    // Match sender by extracted email address or full from field
                    $q->orWhere('from', 'ILIKE', '%' . $senderEmail . '%');
                }
            });

            $emailsToDelete = $query->get();
            $totalDeleted = 0;
            $deletedEmails = [];
            $failedDeletes = [];

            $gmail = new Gmail($this->googleClient);

            foreach ($emailsToDelete as $dbEmail) {
                try {
                    // First, delete from Gmail
                    $gmail->users_messages->delete('me', $dbEmail->gmail_id);
                    
                    // Then fetch full email to save body
                    try {
                        $emailToDelete = $gmail->users_messages->get('me', $dbEmail->gmail_id, ['format' => 'full']);
                        $payload = $emailToDelete->getPayload();
                        
                        // Get email body
                        $emailBody = $this->getEmailBody($payload);
                        
                        // Save body if we have one
                        if ($emailBody) {
                            EmailBody::updateOrCreate(
                                ['email_id' => $dbEmail->id],
                                ['body' => $emailBody]
                            );
                        }
                    } catch (\Exception $e) {
                        // Email already deleted, body saving is optional
                    }
                    
                    // Mark as deleted in database
                    $dbEmail->update(['remote_delete' => true]);
                    
                    $deletedEmails[] = [
                        'gmail_id' => $dbEmail->gmail_id,
                        'from' => $dbEmail->from,
                        'subject' => $dbEmail->subject,
                    ];
                    $totalDeleted++;
                } catch (\Exception $e) {
                    // Log failed deletes but continue
                    $failedDeletes[] = [
                        'gmail_id' => $dbEmail->gmail_id,
                        'from' => $dbEmail->from,
                        'error' => $e->getMessage(),
                    ];
                    continue;
                }
            }

            return response()->json([
                'success' => true,
                'user' => $user->email,
                'searched_senders' => $senderEmails,
                'total_deleted' => $totalDeleted,
                'total_failed' => count($failedDeletes),
                'deleted_emails' => $deletedEmails,
                'failed_deletes' => $failedDeletes,
                'message' => "Successfully deleted $totalDeleted email(s) from specified sender(s)",
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Failed to delete sender emails: ' . $e->getMessage()
            ], 500);
        }
    }

    private function exportStatisticsToExcel(array $stats, string $userEmail)
    {
        $fileName = 'Gmail_Statistics_' . date('Y-m-d_H-i-s') . '.csv';
        
        $response = new StreamedResponse(function () use ($stats, $userEmail) {
            $handle = fopen('php://output', 'w');
            
            // Add BOM for Excel UTF-8 compatibility
            fprintf($handle, chr(0xEF).chr(0xBB).chr(0xBF));
            
            // Header
            fputcsv($handle, ['Gmail Statistics Report']);
            fputcsv($handle, ['User', $userEmail]);
            fputcsv($handle, ['Generated', date('Y-m-d H:i:s')]);
            fputcsv($handle, []);
            
            // General Stats
            fputcsv($handle, ['GENERAL STATISTICS']);
            fputcsv($handle, ['Total Emails', $stats['total_emails']]);
            fputcsv($handle, ['Unread Emails', $stats['unread_emails']]);
            fputcsv($handle, ['Spam Emails', $stats['spam_emails']]);
            fputcsv($handle, []);
            
            // Categories
            fputcsv($handle, ['EMAILS BY CATEGORY']);
            foreach ($stats['emails_by_category'] as $category => $count) {
                fputcsv($handle, [$category, $count]);
            }
            fputcsv($handle, []);
            
            // Unsubscribe
            fputcsv($handle, ['UNSUBSCRIBE STATISTICS']);
            fputcsv($handle, ['Unsubscribed Emails', $stats['unsubscribed_emails']]);
            fputcsv($handle, ['Unsubscribed Percentage', $stats['unsubscribed_percentage'] . '%']);
            fputcsv($handle, []);
            
            // Email Age
            fputcsv($handle, ['EMAILS BY AGE']);
            foreach ($stats['emails_by_age'] as $period => $count) {
                fputcsv($handle, [ucfirst(str_replace('_', ' ', $period)), $count]);
            }
            fputcsv($handle, []);
            
            // Attachments & Open Rate
            fputcsv($handle, ['ENGAGEMENT METRICS']);
            fputcsv($handle, ['Emails with Attachments', $stats['emails_with_attachments']]);
            fputcsv($handle, ['Estimated Opened', $stats['email_open_rate']['estimated_opened']]);
            fputcsv($handle, ['Estimated Unopened', $stats['email_open_rate']['estimated_unopened']]);
            fputcsv($handle, ['Open Rate %', $stats['email_open_rate']['percentage_opened'] . '%']);
            fputcsv($handle, []);
            
            // Top Senders
            fputcsv($handle, ['TOP 5 SENDERS']);
            foreach ($stats['top_5_senders'] as $email => $count) {
                fputcsv($handle, [$email, $count]);
            }
            fputcsv($handle, []);
            
            // Promotions & Newsletters
            fputcsv($handle, ['CONTENT ANALYSIS']);
            fputcsv($handle, ['Emails with Promotions', $stats['emails_with_promotions']]);
            fputcsv($handle, ['Newsletter Emails', $stats['emails_from_newsletters']]);
            fputcsv($handle, []);
            
            // Keywords
            fputcsv($handle, ['KEYWORD FREQUENCY']);
            foreach ($stats['emails_with_keywords'] as $keyword => $count) {
                fputcsv($handle, [ucfirst($keyword), $count]);
            }
            fputcsv($handle, []);
            
            // Peak Time
            fputcsv($handle, ['PEAK ACTIVITY']);
            fputcsv($handle, ['Peak Hour', $stats['time_of_day_with_most_emails']['peak_hour']]);
            fputcsv($handle, ['Emails in Peak Hour', $stats['time_of_day_with_most_emails']['count']]);
            fputcsv($handle, []);
            
            // Thread Info
            fputcsv($handle, ['THREAD INFORMATION']);
            fputcsv($handle, ['Longest Thread ID', $stats['longest_thread']['thread_id'] ?? 'N/A']);
            fputcsv($handle, ['Messages in Longest Thread', $stats['longest_thread']['message_count']]);
            
            fclose($handle);
        });
        
        $response->headers->set('Content-Type', 'text/csv; charset=utf-8');
        $response->headers->set('Content-Disposition', 'attachment; filename="' . $fileName . '"');
        
        return $response;
    }

    private function exportSenderEmails(array $emailsList, array $senderEmails, string $userEmail)
    {
        $fileName = 'Sender_Emails_' . date('Y-m-d_H-i-s') . '.csv';
        
        $response = new StreamedResponse(function () use ($emailsList, $senderEmails, $userEmail) {
            $handle = fopen('php://output', 'w');
            fprintf($handle, chr(0xEF).chr(0xBB).chr(0xBF)); // UTF-8 BOM for Excel
            
            // Header section
            fputcsv($handle, ['Sender Emails Export']);
            fputcsv($handle, ['User', $userEmail]);
            fputcsv($handle, ['Searched Senders', implode('; ', $senderEmails)]);
            fputcsv($handle, ['Export Date', date('Y-m-d H:i:s')]);
            fputcsv($handle, ['Total Emails', count($emailsList)]);
            fputcsv($handle, []); // Blank row
            
            // Column headers
            fputcsv($handle, ['From', 'To', 'Subject', 'Date', 'Snippet']);
            
            // Email data
            foreach ($emailsList as $email) {
                fputcsv($handle, [
                    $email['from'],
                    $email['to'],
                    $email['subject'],
                    $email['date'],
                    $email['snippet'],
                ]);
            }
            
            fclose($handle);
        });
        
        $response->headers->set('Content-Type', 'text/csv; charset=utf-8');
        $response->headers->set('Content-Disposition', 'attachment; filename="' . $fileName . '"');
        
        return $response;
    }

    public function downloadAttachment(Request $request, string $gmail_id, string $part_id)
    {
        $token = $this->extractToken($request);

        if (!$token) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        try {
            [$userId, $accessToken] = explode(':', base64_decode($token), 2);
            
            $user = User::find($userId);
            if (!$user || !$user->google_token) {
                return response()->json(['error' => 'User not found or not authenticated'], 401);
            }

            // Set up Google Client with user token
            $tokenData = json_decode($user->google_token, true);
            $this->googleClient->setAccessToken($tokenData);

            // Refresh token if expired
            if ($this->googleClient->isAccessTokenExpired()) {
                if ($user->google_refresh_token) {
                    try {
                        $this->googleClient->refreshToken($user->google_refresh_token);
                        $newToken = $this->googleClient->getAccessToken();
                        $user->update(['google_token' => json_encode($newToken)]);
                    } catch (\Exception $e) {
                        return response()->json(['error' => 'Failed to refresh token: ' . $e->getMessage()], 401);
                    }
                } else {
                    return response()->json(['error' => 'Token expired and no refresh token available'], 401);
                }
            }

            $service = new Gmail($this->googleClient);

            // Get the message
            $message = $service->users_messages->get('me', $gmail_id, ['format' => 'full']);
            $payload = $message->getPayload();
            $parts = $payload->getParts();

            $attachmentContent = null;
            $filename = null;
            $mimeType = null;

            // Find the attachment by part ID
            if ($parts) {
                foreach ($parts as $part) {
                    if ($part->getPartId() === $part_id && $part->getFilename()) {
                        $filename = $part->getFilename();
                        $mimeType = $part->getMimeType();
                        $attachmentId = $part->getBody()->getAttachmentId();

                        if ($attachmentId) {
                            // Download the attachment
                            $attachment = $service->users_messages_attachments->get('me', $gmail_id, $attachmentId);
                            $attachmentContent = $attachment->getData();
                            // Decode base64url
                            $attachmentContent = str_replace(['-', '_'], ['+', '/'], $attachmentContent);
                            $attachmentContent = base64_decode($attachmentContent);
                        }
                        break;
                    }
                }
            }

            if (!$attachmentContent) {
                return response()->json(['error' => 'Attachment not found'], 404);
            }

            return response($attachmentContent, 200)
                ->header('Content-Type', $mimeType)
                ->header('Content-Disposition', 'attachment; filename="' . $filename . '"');

        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Failed to download attachment: ' . $e->getMessage()
            ], 500);
        }
    }

    public function exportAllEmailsToPdf(Request $request)
    {
        $token = $this->extractToken($request);

        if (!$token) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        try {
            [$userId, $accessToken] = explode(':', base64_decode($token), 2);
            
            $user = User::find($userId);
            if (!$user || !$user->google_token) {
                return response()->json(['error' => 'User not found or not authenticated'], 401);
            }

            // Set up Google Client with user token
            $tokenData = json_decode($user->google_token, true);
            $this->googleClient->setAccessToken($tokenData);

            // Refresh token if expired
            if ($this->googleClient->isAccessTokenExpired()) {
                if ($user->google_refresh_token) {
                    try {
                        $this->googleClient->refreshToken($user->google_refresh_token);
                        $newToken = $this->googleClient->getAccessToken();
                        $user->update(['google_token' => json_encode($newToken)]);
                    } catch (\Exception $e) {
                        return response()->json(['error' => 'Failed to refresh token: ' . $e->getMessage()], 401);
                    }
                } else {
                    return response()->json(['error' => 'Token expired and no refresh token available'], 401);
                }
            }

            $service = new Gmail($this->googleClient);

            $fileName = 'Gmail_Export_' . date('Y-m-d_H-i-s') . '.html';

            // Stream HTML generation to avoid memory exhaustion
            $response = new StreamedResponse(function () use ($service) {
                $handle = fopen('php://output', 'w');

                // Write HTML header
                fwrite($handle, '<html><head><meta charset="UTF-8"><style>
                body { font-family: Arial, sans-serif; margin: 20px; }
                .email { page-break-inside: avoid; margin-bottom: 30px; border: 1px solid #ddd; padding: 15px; }
                .email-header { background-color: #f5f5f5; padding: 10px; margin-bottom: 10px; }
                .email-from { font-weight: bold; }
                .email-subject { font-size: 14px; font-weight: bold; margin: 5px 0; }
                .email-date { color: #666; font-size: 12px; }
                .email-body { margin-top: 10px; line-height: 1.5; }
                .page-break { page-break-before: always; }
            </style></head><body>');

                $emailCount = 0;
                $pageToken = null;

                do {
                    $messages = $service->users_messages->listUsersMessages('me', [
                        'maxResults' => 50,
                        'pageToken' => $pageToken,
                    ]);

                    if ($messages->getMessages()) {
                        foreach ($messages->getMessages() as $messageInfo) {
                            try {
                                $message = $service->users_messages->get('me', $messageInfo->getId(), ['format' => 'full']);
                                $headers = $message->getPayload()->getHeaders();

                                $from = '';
                                $to = '';
                                $subject = '';
                                $date = '';

                                foreach ($headers as $header) {
                                    if ($header->getName() === 'From') $from = $header->getValue();
                                    if ($header->getName() === 'To') $to = $header->getValue();
                                    if ($header->getName() === 'Subject') $subject = $header->getValue();
                                    if ($header->getName() === 'Date') $date = $header->getValue();
                                }

                                // Get email body
                                $body = $this->getEmailBody($message);

                                // Add page break every 10 emails
                                if ($emailCount > 0 && $emailCount % 10 === 0) {
                                    fwrite($handle, '<div class="page-break"></div>');
                                }

                                fwrite($handle, '<div class="email">');
                                fwrite($handle, '<div class="email-header">');
                                fwrite($handle, '<div class="email-from">From: ' . htmlspecialchars($from) . '</div>');
                                fwrite($handle, '<div>To: ' . htmlspecialchars($to) . '</div>');
                                fwrite($handle, '<div class="email-subject">Subject: ' . htmlspecialchars($subject) . '</div>');
                                fwrite($handle, '<div class="email-date">Date: ' . htmlspecialchars($date) . '</div>');
                                fwrite($handle, '</div>');
                                fwrite($handle, '<div class="email-body">' . ($body ?: '[No content]') . '</div>');
                                fwrite($handle, '</div>');

                                $emailCount++;

                                // Force flush every 50 emails to free memory
                                if ($emailCount % 50 === 0) {
                                    flush();
                                    gc_collect_cycles();
                                }
                            } catch (\Exception $e) {
                                // Skip emails that fail to load
                                continue;
                            }
                        }
                    }

                    $pageToken = $messages->getNextPageToken();
                } while ($pageToken);

                fwrite($handle, '</body></html>');
                fclose($handle);
            });

            $response->headers->set('Content-Type', 'text/html; charset=utf-8');
            $response->headers->set('Content-Disposition', 'attachment; filename="' . $fileName . '"');

            return $response;

        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Failed to export emails to PDF: ' . $e->getMessage()
            ], 500);
        }
    }

    public function exportAllEmailsToCsv(Request $request)
    {
        $token = $this->extractToken($request);

        if (!$token) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        try {
            [$userId, $accessToken] = explode(':', base64_decode($token), 2);
            
            $user = User::find($userId);
            if (!$user || !$user->google_token) {
                return response()->json(['error' => 'User not found or not authenticated'], 401);
            }

            // Set up Google Client with user token
            $tokenData = json_decode($user->google_token, true);
            $this->googleClient->setAccessToken($tokenData);

            // Refresh token if expired
            if ($this->googleClient->isAccessTokenExpired()) {
                if ($user->google_refresh_token) {
                    try {
                        $this->googleClient->refreshToken($user->google_refresh_token);
                        $newToken = $this->googleClient->getAccessToken();
                        $user->update(['google_token' => json_encode($newToken)]);
                    } catch (\Exception $e) {
                        return response()->json(['error' => 'Failed to refresh token: ' . $e->getMessage()], 401);
                    }
                } else {
                    return response()->json(['error' => 'Token expired and no refresh token available'], 401);
                }
            }

            $service = new Gmail($this->googleClient);

            $fileName = 'Gmail_Export_' . date('Y-m-d_H-i-s') . '.csv';

            $response = new StreamedResponse(function () use ($service) {
                $handle = fopen('php://output', 'w');
                fprintf($handle, chr(0xEF).chr(0xBB).chr(0xBF)); // UTF-8 BOM

                // CSV Headers
                fputcsv($handle, ['From', 'To', 'Subject', 'Date', 'Body']);

                // Fetch and stream all emails
                $pageToken = null;
                $emailCount = 0;

                do {
                    $messages = $service->users_messages->listUsersMessages('me', [
                        'maxResults' => 50,
                        'pageToken' => $pageToken,
                    ]);

                    if ($messages->getMessages()) {
                        foreach ($messages->getMessages() as $messageInfo) {
                            try {
                                $message = $service->users_messages->get('me', $messageInfo->getId(), ['format' => 'full']);
                                $headers = $message->getPayload()->getHeaders();

                                $from = '';
                                $to = '';
                                $subject = '';
                                $date = '';

                                foreach ($headers as $header) {
                                    if ($header->getName() === 'From') $from = $header->getValue();
                                    if ($header->getName() === 'To') $to = $header->getValue();
                                    if ($header->getName() === 'Subject') $subject = $header->getValue();
                                    if ($header->getName() === 'Date') $date = $header->getValue();
                                }

                                // Get email body
                                $body = strip_tags($this->getEmailBody($message));

                                fputcsv($handle, [$from, $to, $subject, $date, $body]);

                                $emailCount++;

                                // Force flush every 50 emails to free memory
                                if ($emailCount % 50 === 0) {
                                    flush();
                                    gc_collect_cycles();
                                }
                            } catch (\Exception $e) {
                                // Skip emails that fail to load
                                continue;
                            }
                        }
                    }

                    $pageToken = $messages->getNextPageToken();
                } while ($pageToken);

                fclose($handle);
            });

            $response->headers->set('Content-Type', 'text/csv; charset=utf-8');
            $response->headers->set('Content-Disposition', 'attachment; filename="' . $fileName . '"');

            return $response;

        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Failed to export emails to CSV: ' . $e->getMessage()
            ], 500);
        }
    }

    public function getAuthUrl(): JsonResponse
    {
        return response()->json([
            'auth_url' => $this->googleClient->createAuthUrl()
        ]);
    }
}
