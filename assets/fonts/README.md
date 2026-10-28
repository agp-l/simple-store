# Inter

Inter variable font, regular style, hosted locally so ordinary page rendering does not contact Google Fonts.

Upstream: https://github.com/rsms/inter/blob/master/docs/font-files/InterVariable.woff2

License: SIL Open Font License 1.1, included in `Inter-LICENSE.txt`. Retrieved 2026-10-28.

The WOFF2 file is a FontTools subset retaining Latin and extended Latin (including Czech), punctuation, currency signs and arrows. Weight variation 100–900 and OpenType layout features are retained. Other scripts use the existing system font fallbacks.

Reproduction with FontTools:

```sh
python -m fontTools.subset InterVariable.woff2 --output-file=InterVariable-latin.woff2 --flavor=woff2 --unicodes='U+0000-024F,U+1E00-1EFF,U+2000-206F,U+20A0-20CF,U+2100-214F,U+2190-21FF,U+2212,U+FEFF,U+FFFD' --layout-features='*'
```
