import { fieldLabel } from './field-labels';

describe( 'fieldLabel', () => {
	it( 'renders the server-provided label verbatim', () => {
		expect(
			fieldLabel( {
				field_label: 'Attribute: Strength',
				field_key: 'x',
				segment_key: 'y',
			} )
		).toBe( 'Attribute: Strength' );
	} );

	it( 'falls back to a humanized key when no label is sent', () => {
		expect(
			fieldLabel( {
				field_key: 'post_excerpt',
				segment_key: 'post_excerpt',
			} )
		).toBe( 'Post Excerpt' );
	} );

	it( 'uses the segment key when there is no field key', () => {
		expect(
			fieldLabel( { field_key: '', segment_key: 'b:uuid:content' } )
		).toBe( 'B Uuid Content' );
	} );

	it( 'never returns an empty string', () => {
		expect( fieldLabel( { field_key: '', segment_key: '' } ) ).toBe(
			'Segment'
		);
	} );
} );
