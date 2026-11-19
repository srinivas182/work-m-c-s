<style>
  body { margin: 0; font-family: system-ui, sans-serif; color: #1b1640; background: #fafafa; }
  main { max-width: 32rem; margin: 0 auto; padding: 2rem 1rem; }
  h1 { font-size: 1.5rem; margin: .25rem 0 1rem; }
  p { color: #4b4766; line-height: 1.5; }
  fieldset { border: 0; padding: 0; margin: 0 0 1rem; }
  legend { font-weight: 600; margin-bottom: .5rem; }
  .stars { display: flex; gap: .5rem; flex-wrap: wrap; }
  .stars label { flex: 1 1 3.5rem; min-width: 3.5rem; text-align: center; border: 2px solid #d9d6ea; border-radius: .75rem; padding: .75rem 0; font-size: 1.25rem; cursor: pointer; background: #fff; }
  .stars input { position: absolute; opacity: 0; pointer-events: none; }
  .stars input:checked + span { font-weight: 700; }
  .stars label:has(input:checked) { border-color: #1b1640; background: #ecebf5; }
  .stars input:focus-visible + span { outline: 3px solid #6d4aff; outline-offset: 4px; }
  label.block { display: block; font-weight: 600; margin: 1rem 0 .35rem; }
  textarea { width: 100%; box-sizing: border-box; font: inherit; padding: .6rem; border: 1px solid #d9d6ea; border-radius: .5rem; min-height: 5rem; }
  .hint { font-size: .875rem; margin: .25rem 0 0; }
  button, .button { display: block; text-align: center; text-decoration: none; font: inherit; font-weight: 600; padding: .8rem 1.4rem; border: 0; border-radius: .5rem; background: #1b1640; color: #fff; cursor: pointer; width: 100%; box-sizing: border-box; margin-top: 1.25rem; }
  .problem { color: #9f1239; font-weight: 600; }
</style>
