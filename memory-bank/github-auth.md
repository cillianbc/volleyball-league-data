# GitHub Authentication for Volleyball Import Plugin

## Personal Access Token (PAT) Requirements

Based on GitHub REST API documentation, to fetch JSON files from a repository (e.g., GET /repos/{owner}/{repo}/contents/current/{league}.json), the PAT needs specific scopes/permissions:

### Classic PAT (Recommended for Simplicity)
- **Scope**: `repo` (full control of private repositories, including read/write access to contents, metadata, issues, etc.).
  - This grants read access to repository contents, sufficient for fetching files.
  - Alternative: `public_repo` if the repo is public (read-only for public repos).
- **Why**: Classic PATs have broader scopes but are easier to set up. Use if fine-grained not needed.
- **Creation**: GitHub Settings > Developer settings > Personal access tokens > Tokens (classic) > Generate new token > Select `repo` scope > Set expiration.

### Fine-Grained PAT (Recommended for Security)
- **Permissions**: 
  - Repository access: Select the specific repository (e.g., cillianbrackenconway/DVC-league-tables).
  - Repository permissions: `Contents: Read-only` (allows reading repository contents like files and directories).
  - Optional: `Metadata: Read-only` for branch/repo info.
- **Why**: More granular; limits access to only necessary repo and permissions, reducing risk if token compromised.
- **Creation**: GitHub Settings > Developer settings > Fine-grained tokens > Generate new token > Select resource owner/repo > Set `Contents: Read` permission > Set expiration (e.g., 90 days).
- **Note**: Fine-grained tokens support fewer API endpoints but cover contents fetching. Ensure the token is authorized for SSO if using GitHub Enterprise.

### Usage in Plugin
- Store token securely in WP options: `update_option('volleyball_github_token', $token);`.
- API Request Headers: `Authorization: Bearer {token}` (for fine-grained) or `Authorization: token {token}` (for classic).
- Example cURL: 
```
curl -H "Authorization: Bearer YOUR_TOKEN" \
     -H "Accept: application/vnd.github+json" \
     https://api.github.com/repos/OWNER/REPO/contents/current/league.json
```
- Response: Base64-encoded file content; decode with `base64_decode($response['content'])` then `json_decode()`.

### Security Best Practices
- Keep tokens secret; regenerate if compromised.
- Use expiration dates; monitor usage via GitHub.
- For public repos, no token needed (but rate-limited to 60 req/hour vs 5000 with auth).
- In WP: Validate token on settings save; test API call during config.

## References
- GitHub Docs: [Creating a PAT](https://docs.github.com/en/authentication/keeping-your-account-and-data-secure/creating-a-personal-access-token)
- [Scopes for OAuth Apps](https://docs.github.com/en/apps/oauth-apps/building-oauth-apps/scopes-for-oauth-apps)
- [Permissions for Fine-Grained Tokens](https://docs.github.com/en/rest/authentication/permissions-required-for-fine-grained-personal-access-tokens)
- Retrieved via Context7 on 2025-09-08.