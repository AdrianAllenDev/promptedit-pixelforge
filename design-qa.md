# Night Shift workspace review

The selected mobile reference is `../design-directions/02-night-shift.png` in the design workspace. The comparison uses a 390 × 844 CSS-pixel viewport with wallets disabled, matching the original design stage. [Captured workspace](docs/qa/night-shift-390x844.png)

The capture shows the prompt, square size, medium quality, collapsed references, exact price range beside Generate, a processing row and a loaded lighthouse image in the successful row. The dark surface, lime action and timeline markers follow the reference. The implemented timeline also shows each prompt and the pending charge. This places the final charge below the first viewport, still visible in the full-page capture. The mobile layout preserves the exact estimate and fits the image without horizontal scrolling.

Playwright creates both generations through the workspace against the local provider fixture. The lighthouse thumbnail comes from the chosen mockup. The test checks that the image decodes, the newer row is processing, and at least 90% of the successful image is in the initial viewport. It saves `night-shift-390x844.png` and `night-shift-390-full.png` in the CI screenshot artifact. The generation, wallet, admin report and review flows also check widths of 360, 390, 768 and 1440 px. [Browser coverage](tests/Browser/generation.spec.ts)
