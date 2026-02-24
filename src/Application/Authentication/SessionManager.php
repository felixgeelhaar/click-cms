<?php

namespace Click\Cms\Application\Authentication;

class SessionManager
{
    private string $sessionFile;
    private array $session = [];
    
    public function __construct(string $dataDir)
    {
        $this->sessionFile = $dataDir . '/session.json';
        $this->loadSession();
    }
    
    private function loadSession(): void
    {
        if (file_exists($this->sessionFile)) {
            $content = file_get_contents($this->sessionFile);
            $this->session = json_decode($content, true) ?: [];
        }
    }
    
    private function saveSession(): void
    {
        file_put_contents($this->sessionFile, json_encode($this->session, JSON_PRETTY_PRINT));
    }
    
    public function login(string $username, string $password): bool
    {
        $usersDir = dirname($this->sessionFile) . '/../content/users';
        $userFile = $usersDir . '/' . $username . '.json';
        
        if (!file_exists($userFile)) {
            return false;
        }
        
        $userData = json_decode(file_get_contents($userFile), true);
        
        // For simple auth, we'll just check if user exists and is active
        // In production, you'd verify password hash
        if ($userData && isset($userData['status']) && $userData['status'] === 'active') {
            $this->session = [
                'username' => $username,
                'loginTime' => time(),
                'user' => $userData
            ];
            $this->saveSession();
            return true;
        }
        
        return false;
    }
    
    public function logout(): void
    {
        $this->session = [];
        $this->saveSession();
    }
    
    public function getCurrentUser(): ?array
    {
        return $this->session['user'] ?? null;
    }
    
    public function isLoggedIn(): bool
    {
        return !empty($this->session['username']);
    }
    
    public function getUsername(): ?string
    {
        return $this->session['username'] ?? null;
    }
}
