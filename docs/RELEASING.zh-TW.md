# Site Add-on Watchdog 1.8.3 發布手冊

這份流程以 Windows、TortoiseSVN、桌面上的 `wp-svn` 工作副本，以及 GitHub repository `happyloa/site-add-on-watchdog` 為準。

> 重要：WordPress.org 的 `assets`、`trunk`、`tags` 是三個不同用途。圖示與 banner 只能放在 SVN 最上層的 `assets`；可執行外掛檔放在 `trunk` 與版本 tag。不要把 Git 的 `tests`、`vendor`、`artwork`、`scripts` 或 `wordpress-org-assets` 整包複製進 `trunk`。

## 一、發布前檢查

1. 確認外掛版本三處都是 `1.8.3`：
   - `site-add-on-watchdog.php` 的 `Version`
   - `src/Version.php` 的 `Version::NUMBER`
   - `readme.txt` 的 `Stable tag`
2. 在 Git 專案執行完整測試：

   ```powershell
   docker run --rm -v "C:\Users\User\Desktop\site-add-on-watchdog:/app" -w /app composer:2 sh -lc "composer validate --strict && composer test && composer lint"
   ```

3. 建立安裝 ZIP：

   ```powershell
   powershell -ExecutionPolicy Bypass -File .\scripts\build-release.ps1 -Version 1.8.3
   ```

   成品會是 `dist\site-add-on-watchdog-1.8.3.zip`，ZIP 內第一層必須是 `site-add-on-watchdog` 資料夾。
4. 在一個非正式站安裝 ZIP、啟用、開啟 Watchdog 後台、手動掃描一次，並逐一使用 Email、Discord、Slack、Teams、Generic Webhook 的「儲存並測試」。正式 webhook 憑證不能放進 Git、截圖或 commit 訊息。
5. 確認 `git status` 乾淨，所有預定修改都已 commit。

## 二、先同步 GitHub main 並看 CI

1. 將本機 `main` push 到 GitHub，但先不要建立 `1.8.3` tag。
2. 到 GitHub 的 Actions 頁面，確認 PHP 8.1、8.2、8.3、8.4、8.5 與 WordPress Plugin Check 全綠。
3. 若 CI 失敗，先在 Git 修正、commit、再 push；不要把失敗版本送進 SVN。

## 三、用 TortoiseSVN 更新桌面 wp-svn

1. 在檔案總管開啟 `C:\Users\User\Desktop\wp-svn`。
2. 在資料夾空白處按右鍵，選 **SVN Update**。
3. 再按右鍵，選 **TortoiseSVN → Check for modifications**。
4. 如果出現不是這次發布造成的本機修改，先停止，不要覆蓋；確認來源後再處理。

## 四、更新 SVN trunk

1. 解壓縮 `dist\site-add-on-watchdog-1.8.3.zip`。
2. 打開 ZIP 內的 `site-add-on-watchdog` 資料夾，把「裡面的內容」複製到 `wp-svn\trunk`，不是再多包一層外掛資料夾。
3. 對 trunk 中已不再存在於新套件的舊檔案，使用 **TortoiseSVN → Delete**；不要只留著舊檔，以免使用者更新後仍載入過期程式。
4. 對新檔案或新資料夾使用 **TortoiseSVN → Add**。
5. 用 **Check for modifications** 檢查：
   - `readme.txt` Stable tag 是 `1.8.3`
   - 主 PHP 檔版本是 `1.8.3`
   - trunk 沒有 `vendor`、`tests`、`.git`、`.github`、`artwork`、`scripts` 或 Composer 開發檔
6. 逐一雙擊修改檔查看 diff，確認沒有測試密碼、webhook URL、API key 或本機路徑。

## 五、更新 SVN assets

把 Git 專案 `wordpress-org-assets` 裡的四張圖複製到 `wp-svn\assets`：

- `icon-128x128.png`
- `icon-256x256.png`
- `banner-772x250.png`
- `banner-1544x500.png`

新檔案使用 **TortoiseSVN → Add**。選取 PNG 後開啟 **TortoiseSVN → Properties**，確認 `svn:mime-type` 是 `image/png`。這些檔案不可放入 `trunk\assets` 或 `tags\1.8.3\assets`。

## 六、commit trunk 與 assets

1. 在 `wp-svn` 根目錄右鍵選 **SVN Commit**。
2. 勾選這次的 trunk 與 assets 變更，包含新增與刪除項目。
3. 建議 commit 訊息：

   ```text
   Release 1.8.3: WordPress 7.1.2 compatibility and resilient directory lookups
   ```

4. Commit 成功後記下 revision number。

## 七、用 TortoiseSVN 建立 1.8.3 tag

1. 在 `wp-svn\trunk` 上按右鍵，選 **TortoiseSVN → Branch/tag**。
2. `To URL` 填入同一個 SVN repository 下的 `/tags/1.8.3`。
3. 選擇剛才 commit 完成的 HEAD revision；來源必須是乾淨且已 commit 的 trunk。
4. Log message 填：

   ```text
   Tag version 1.8.3
   ```

5. 按 OK 建立 server-side copy。完成後可在 Repo-browser 確認 `tags/1.8.3` 存在。
6. 已發布的 tag 視為不可變；若發現問題，回到 trunk 修正並發布 `1.8.4`，不要直接改 `tags/1.8.3`。

## 八、WordPress.org 發布確認

1. WordPress.org 偵測到新 tag 後，若此外掛有啟用 Release Confirmation，開啟通知信中的連結或外掛頁面的 Release Management 提示。
2. 確認 `1.8.3` 發布。沒有完成這一步時，tag 可能已存在但使用者還收不到更新。
3. 等待 CDN 與目錄快取更新；圖示和 banner 通常幾分鐘，尖峰時可能數小時。
4. 驗證外掛頁顯示版本 1.8.3、Tested up to 7.1（代表 7.1.2）、新 changelog、圖示與 banner。

## 九、建立 Git tag 與 GitHub Release

1. 在通過 CI 的同一個 commit 建立 annotated tag：

   ```powershell
   git tag -a 1.8.3 -m "Site Add-on Watchdog 1.8.3"
   git push origin 1.8.3
   ```

2. 在 GitHub 建立 `1.8.3` Release，標題使用 `Site Add-on Watchdog 1.8.3`。
3. Release notes 使用 `CHANGELOG.md` 的 1.8.3 內容。
4. 上傳 `dist\site-add-on-watchdog-1.8.3.zip`，再發布 Release。

## 十、發布後快速驗證與回復策略

1. 從 WordPress.org 或 GitHub Release 重新下載公開 ZIP，不要使用本機來源檔代替。
2. 在測試站從 1.8.2 更新到 1.8.3，確認設定、忽略清單、歷史、cron secret 與通知設定都有保留。
3. 執行手動掃描，檢查後台、WP-Cron、REST cron 與通知佇列。
4. 若只是不影響網站的文件或目錄資產問題，可修正 trunk/assets 後重新 commit；不要改已發布 tag。
5. 若程式有回歸，立即在 Git 與 SVN trunk 修正、把版本升為 1.8.4、跑完同一套測試並建立新 tag。不要刪除或覆寫 1.8.3 tag。

## 建議由 Codex 代操作時的確認點

等你明確說「可以操作 SVN／GitHub 發布」後，Codex 可以用背景指令先唯讀檢查 `wp-svn`、同步檔案、顯示預計新增／修改／刪除清單，再進行 commit、tag、push 與 GitHub Release。任何外部發布動作前都應再次顯示目標 repository、版本、revision/commit 與資產清單，避免發錯位置。
